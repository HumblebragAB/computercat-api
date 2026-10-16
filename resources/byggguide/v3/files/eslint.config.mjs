import { defineConfig, globalIgnores } from 'eslint/config';
import nextVitals from 'eslint-config-next/core-web-vitals';
import nextTs from 'eslint-config-next/typescript';

// Byggguide v2: säkerhetsregler (spec delprojekt 3, del 3, punkt 3). Stäng aldrig av dem i spelet.
// NEXT_PUBLIC_* bakas in i den publika koden vid bygget; bara spelets slug och API-adressen får läsas.
// Både JSX-attributet och nyckeln i ett props-objekt (createElement, spridda props), med identifierare eller sträng som nyckel.
const DANGEROUS_HTML = {
  selector:
    "JSXAttribute[name.name='dangerouslySetInnerHTML'], Property[key.name='dangerouslySetInnerHTML'], Property[key.value='dangerouslySetInnerHTML']",
  message: 'dangerouslySetInnerHTML är förbjudet (byggguide v3). Rendera texten som text.',
};
const OTHER_PUBLIC_ENV = {
  selector:
    "MemberExpression[object.type='MemberExpression'][object.object.name='process'][object.property.name='env'][computed=false][property.name=/^NEXT_PUBLIC_/]:not([property.name=/^NEXT_PUBLIC_COMPUTERCAT_(GAME|URL)$/])",
  message: 'Bara NEXT_PUBLIC_COMPUTERCAT_GAME och NEXT_PUBLIC_COMPUTERCAT_URL får läsas (byggguide v3). Allt i NEXT_PUBLIC_* blir publikt.',
};
// I src/: varje åtkomst till process.env (även globalThis.process.env och process['env']) som inte
// är en direkt punktläsning av NODE_ENV eller de två tillåtna NEXT_PUBLIC-namnen. Det stänger
// destrukturering, alias (const env = process.env), spridning och hakparentes.
const ENV_OBJECT = {
  selector:
    "MemberExpression:matches([property.name='env'][computed=false], [property.value='env'][computed=true]):matches([object.name='process'], [object.property.name='process']):not(MemberExpression[computed=false][property.name=/^(NODE_ENV|NEXT_PUBLIC_COMPUTERCAT_(GAME|URL))$/] > .object)",
  message:
    'Läs bara process.env.NODE_ENV, process.env.NEXT_PUBLIC_COMPUTERCAT_GAME och process.env.NEXT_PUBLIC_COMPUTERCAT_URL, direkt med punkt (byggguide v3). Inga alias, destrukturering, spridning eller hakparentes.',
};
// Tilldelning och hakparentesläsning av egenskapen på ett element (el.dangerouslySetInnerHTML = ..., x['dangerouslySetInnerHTML']).
const DANGEROUS_HTML_MEMBER = {
  selector:
    "MemberExpression[property.name='dangerouslySetInnerHTML'][computed=false], MemberExpression[property.value='dangerouslySetInnerHTML'][computed=true]",
  message: 'dangerouslySetInnerHTML är förbjudet (byggguide v3). Rendera texten som text.',
};
// v3 (R6): i spelets kod (utom testfiler) får identifieraren process bara stå som process.env, och
// ingen egenskap eller destrukturering får heta process. Stänger const { env } = process,
// const p = process, self['process'], globalThis.process och { process: p } = globalThis.
const PROCESS_MESSAGE =
  'process får bara användas som process.env.NODE_ENV, process.env.NEXT_PUBLIC_COMPUTERCAT_GAME eller process.env.NEXT_PUBLIC_COMPUTERCAT_URL i spelets kod (byggguide v3). Inga alias, ingen destrukturering och inga omvägar via globalThis, self eller window.';
const PROCESS_REFERENCE = {
  selector:
    "Identifier[name='process']:not(MemberExpression[computed=false][property.name='env'] > .object):not(MemberExpression[computed=false] > .property):not(Property[shorthand=false] > .key)",
  message: PROCESS_MESSAGE,
};
const PROCESS_PROPERTY = {
  selector:
    "MemberExpression[computed=false][property.name='process'], MemberExpression[computed=true][property.value='process'], MemberExpression[computed=true][property.type='TemplateLiteral'][property.quasis.0.value.cooked='process'], ObjectPattern > Property[key.name='process'], ObjectPattern > Property[key.value='process']",
  message: PROCESS_MESSAGE,
};
// require('process'), require('node:process') och import('…'): modulen ger samma objekt som process.
const PROCESS_MODULE_CALL = {
  selector:
    "CallExpression[callee.name='require'][arguments.0.value=/^(node:)?process$/], ImportExpression[source.value=/^(node:)?process$/]",
  message: PROCESS_MESSAGE,
};
// import … from 'process' / 'node:process' i alla former (namngivet, default, namnrymd).
const PROCESS_IMPORTS = [
  'error',
  { paths: [{ name: 'process', message: PROCESS_MESSAGE }, { name: 'node:process', message: PROCESS_MESSAGE }] },
];

export default defineConfig([
  ...nextVitals,
  ...nextTs,
  {
    rules: {
      'no-eval': 'error',
      'no-implied-eval': 'error',
      'no-new-func': 'error',
      'no-restricted-syntax': ['error', DANGEROUS_HTML, DANGEROUS_HTML_MEMBER, OTHER_PUBLIC_ENV],
    },
  },
  // Spelets källkod (det som bakas in i out/). Ett senare block ersätter regelns hela
  // värde för sina filer, därför upprepas de tre första väljarna här.
  {
    files: ['src/**/*.{ts,tsx}'],
    rules: {
      'no-restricted-syntax': ['error', DANGEROUS_HTML, DANGEROUS_HTML_MEMBER, OTHER_PUBLIC_ENV, ENV_OBJECT],
    },
  },
  // Utom testfiler (de läser process.cwd() och bakas aldrig in): process bara som process.env.
  {
    files: ['src/**/*.{ts,tsx}'],
    ignores: ['src/**/*.test.{ts,tsx}'],
    rules: {
      'no-restricted-syntax': ['error', DANGEROUS_HTML, DANGEROUS_HTML_MEMBER, OTHER_PUBLIC_ENV, ENV_OBJECT, PROCESS_REFERENCE, PROCESS_PROPERTY, PROCESS_MODULE_CALL],
      'no-restricted-imports': PROCESS_IMPORTS,
    },
  },
  // ios/ är spelets Capacitor-projekt; ios/App/App/public är en kopia av out/.
  globalIgnores(['.next/**', 'out/**', 'build/**', 'next-env.d.ts', '.claude/**', 'ios/**']),
]);
