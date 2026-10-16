import type { Authed } from './auth';
import { isRecord } from './http';
import { dropReason } from './queue';
import { readJson, writeJson, type KeyValueStore } from './storage';

/** AchievementDefinitionResource.php. description är "???" för hemliga. */
export type AchievementDefinition = {
  id: number;
  slug: string;
  name: string;
  description: string;
  icon: string;
  is_secret: boolean;
  sort_order: number;
};
/** UserAchievementResource.php. */
export type UnlockedAchievement = { slug: string; name: string; description: string; icon: string; unlocked_at: string | null };
export type DroppedAchievement = { slug: string; reason: string };
export type AchievementFlushResult = {
  /** Slugs som servern tog emot (upplåsta, redan upplåsta, okända och avvisade). */
  sent: number;
  unlocked: UnlockedAchievement[];
  /** Ingen aktiv definition med den sluggen: felstavad eller inte skapad med upsert_achievement. */
  unknown: string[];
  /** Avvisade av rimlighetskontrollen (settings.achievement_thresholds mot molnsparningen). */
  rejected: string[];
  dropped: DroppedAchievement[];
  remaining: number;
};

/** AchievementRules: slug högst 50 tecken. */
export const MAX_ACHIEVEMENT_SLUG = 50;
/** AchievementController::store: 1–10 slugs per anrop. */
export const ACHIEVEMENT_BATCH = 10;
/** SlugRule::PATTERN i computercat-api: gemener, siffror, -, _ och ., först en bokstav eller siffra. */
export const ACHIEVEMENT_SLUG = /^[a-z0-9][a-z0-9_.-]*$/;

const seg = encodeURIComponent;

const isValidSlug = (s: unknown): s is string =>
  typeof s === 'string' && s.length <= MAX_ACHIEVEMENT_SLUG && ACHIEVEMENT_SLUG.test(s);

/** Synkront, som assertScore: en felaktig slug ska aldrig fälla en hel omgång med 422 eller bli tyst "unknown". */
export function assertAchievementSlug(slug: unknown): asserts slug is string {
  if (!isValidSlug(slug)) {
    throw new RangeError(
      `achievement-slug ska ha högst ${MAX_ACHIEVEMENT_SLUG} tecken: gemener, siffror, -, _ och ., först en bokstav eller siffra (som upsert_achievement); fick ${JSON.stringify(slug)}`,
    );
  }
}

const isArray = (v: unknown): v is unknown[] => Array.isArray(v);
const isString = (v: unknown): v is string => typeof v === 'string';
const isUnlocked = (d: unknown): d is UnlockedAchievement =>
  isRecord(d) &&
  isString(d.slug) &&
  isString(d.name) &&
  isString(d.description) &&
  isString(d.icon) &&
  (d.unlocked_at === null || isString(d.unlocked_at));
const isDefinition = (d: unknown): d is AchievementDefinition =>
  isRecord(d) &&
  typeof d.id === 'number' &&
  isString(d.slug) &&
  isString(d.name) &&
  isString(d.description) &&
  isString(d.icon) &&
  typeof d.is_secret === 'boolean' &&
  typeof d.sort_order === 'number';

export type AchievementDeps = { storage: KeyValueStore; game: string; authed: Authed };

export function createAchievements(deps: AchievementDeps) {
  const base = `/games/${seg(deps.game)}/achievements`;
  const key = `computercat:${deps.game}:achievement-queue`;
  let chain: Promise<unknown> = Promise.resolve();

  // Samma mönster som resultatkön: inget hålls i minnet, och varje ändring läser, ändrar och
  // skriver i ett synkront steg, så att flikar som delar lagringen inte skriver över varandra.
  const load = (): string[] => {
    const raw = readJson(deps.storage, key, isArray) ?? [];
    const valid = raw.filter(isValidSlug);
    if (valid.length < raw.length) {
      console.warn(`[computercat] ${raw.length - valid.length} ogiltiga poster i achievementkön togs bort`);
      writeJson(deps.storage, key, valid);
    }
    return valid;
  };
  const change = (fn: (current: string[]) => string[]): boolean => writeJson(deps.storage, key, fn(load()));

  async function read<T>(path: string, valid: (item: unknown) => item is T): Promise<T[]> {
    const res = await deps.authed<unknown>('GET', path);
    if (!isRecord(res) || !Array.isArray(res.data) || !res.data.every(valid)) {
      throw new Error(`Oväntat svar från GET ${path}`);
    }
    return res.data;
  }

  async function submit(slugs: string[]) {
    const res = await deps.authed<unknown>('POST', base, { slugs });
    const bad = () => new Error(`Oväntat svar från POST ${base}`);
    // unknown och rejected saknas hos en server före ändringen: då räknas allt som skickat.
    const list = (v: unknown): string[] => {
      if (v === undefined) return [];
      if (!Array.isArray(v) || !v.every(isString)) throw bad();
      return v;
    };
    if (!isRecord(res) || !Array.isArray(res.unlocked) || !res.unlocked.every(isUnlocked)) throw bad();
    return { unlocked: res.unlocked, unknown: list(res.unknown), rejected: list(res.rejected) };
  }

  async function run(): Promise<AchievementFlushResult> {
    const result: AchievementFlushResult = { sent: 0, unlocked: [], unknown: [], rejected: [], dropped: [], remaining: 0 };
    // Ögonblicksbild: bara det som låg i kön nu är den här körningens. Det som köas under körningen
    // tar nästa kedjade körning (report kedjar alltid en egen), så resultatet når rätt anropare.
    const mine = new Set(load());
    for (;;) {
      const batch = load()
        .filter((s) => mine.has(s))
        .slice(0, ACHIEVEMENT_BATCH);
      if (batch.length === 0) break;
      const done = new Set(batch);
      const removeBatch = () => change((current) => current.filter((s) => !done.has(s)));
      try {
        const r = await submit(batch);
        const removed = removeBatch();
        result.sent += batch.length;
        result.unlocked.push(...r.unlocked);
        result.unknown.push(...r.unknown);
        result.rejected.push(...r.rejected);
        if (r.unknown.length > 0) console.warn(`[computercat] okända achievements (ingen aktiv definition): ${r.unknown.join(', ')}`);
        if (r.rejected.length > 0) console.warn(`[computercat] achievements avvisade av rimlighetskontrollen: ${r.rejected.join(', ')}`);
        if (!removed) {
          console.error('[computercat] kunde inte ta bort skickade achievements ur kön; tömningen avbryts');
          break;
        }
      } catch (e) {
        const reason = dropReason(e);
        if (reason === null) break; // tillfälligt fel: försök senare
        const removed = removeBatch();
        for (const slug of batch) result.dropped.push({ slug, reason });
        console.error(`[computercat] achievements ${batch.join(', ')} avvisades: ${reason}`);
        if (!removed) break;
      }
    }
    result.remaining = load().length;
    return result;
  }

  /**
   * Körningar läggs efter varandra, så samma omgång skickas aldrig två gånger parallellt i en flik.
   * En körning skickar bara det som låg i kön när den startade; senare köade slugs tillhör nästa körning.
   */
  function flush(): Promise<AchievementFlushResult> {
    const p = chain.then(run);
    chain = p.catch(() => undefined);
    return p;
  }

  /**
   * Köa upplåsningar lokalt (utan dubbletter) och försök skicka direkt. Spara upplåsningen i spelets
   * egen sparning först, och rapportera hela listan vid varje start (servern låser upp en gång).
   * `flushed` gäller körningen som hör till det här anropet; slugs köade av andra anrop under en
   * pågående körning ingår inte i dess resultat.
   */
  function report(slugs: readonly string[]): { queued: string[]; flushed: Promise<AchievementFlushResult> } {
    if (!Array.isArray(slugs)) throw new RangeError('report tar en lista med slugs');
    for (const slug of slugs) assertAchievementSlug(slug);
    const queued = [...new Set(slugs)];
    if (!change((current) => [...current, ...queued.filter((s) => !current.includes(s))])) {
      throw new Error('Kunde inte spara upplåsningen lokalt (lagringen är full eller blockerad); den skickades inte.');
    }
    return { queued, flushed: flush() };
  }

  return {
    list: () => read<AchievementDefinition>(base, isDefinition),
    mine: () => read<UnlockedAchievement>(`${base}/me`, isUnlocked),
    report,
    flush,
    pending: () => load(),
  };
}
