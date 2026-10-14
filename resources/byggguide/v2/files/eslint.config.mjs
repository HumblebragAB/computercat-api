import { defineConfig, globalIgnores } from 'eslint/config';
import nextVitals from 'eslint-config-next/core-web-vitals';
import nextTs from 'eslint-config-next/typescript';

// Byggguide v2: säkerhetsregler (spec delprojekt 3, del 3, punkt 3). Stäng aldrig av dem i spelet.
// NEXT_PUBLIC_* bakas in i den publika koden vid bygget; bara spelets slug och API-adressen får läsas.
// Både JSX-attributet och nyckeln i ett props-objekt (createElement, spridda props), med identifierare eller sträng som nyckel.
const DANGEROUS_HTML = {
  selector:
    "JSXAttribute[name.name='dangerouslySetInnerHTML'], Property[key.name='dangerouslySetInnerHTML'], Property[key.value='dangerouslySetInnerHTML']",
  message: 'dangerouslySetInnerHTML är förbjudet (byggguide v2). Rendera texten som text.',
};
const OTHER_PUBLIC_ENV = {
  selector:
    "MemberExpression[object.type='MemberExpression'][object.object.name='process'][object.property.name='env'][computed=false][property.name=/^NEXT_PUBLIC_/]:not([property.name=/^NEXT_PUBLIC_COMPUTERCAT_(GAME|URL)$/])",
  message: 'Bara NEXT_PUBLIC_COMPUTERCAT_GAME och NEXT_PUBLIC_COMPUTERCAT_URL får läsas (byggguide v2). Allt i NEXT_PUBLIC_* blir publikt.',
};
// I src/: varje åtkomst till process.env (även globalThis.process.env och process['env']) som inte
// är en direkt punktläsning av NODE_ENV eller de två tillåtna NEXT_PUBLIC-namnen. Det stänger
// destrukturering, alias (const env = process.env), spridning och hakparentes.
const ENV_OBJECT = {
  selector:
    "MemberExpression:matches([property.name='env'][computed=false], [property.value='env'][computed=true]):matches([object.name='process'], [object.property.name='process']):not(MemberExpression[computed=false][property.name=/^(NODE_ENV|NEXT_PUBLIC_COMPUTERCAT_(GAME|URL))$/] > .object)",
  message:
    'Läs bara process.env.NODE_ENV, process.env.NEXT_PUBLIC_COMPUTERCAT_GAME och process.env.NEXT_PUBLIC_COMPUTERCAT_URL, direkt med punkt (byggguide v2). Inga alias, destrukturering, spridning eller hakparentes.',
};
// Tilldelning och hakparentesläsning av egenskapen på ett element (el.dangerouslySetInnerHTML = ..., x['dangerouslySetInnerHTML']).
const DANGEROUS_HTML_MEMBER = {
  selector:
    "MemberExpression[property.name='dangerouslySetInnerHTML'][computed=false], MemberExpression[property.value='dangerouslySetInnerHTML'][computed=true]",
  message: 'dangerouslySetInnerHTML är förbjudet (byggguide v2). Rendera texten som text.',
};

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
  globalIgnores(['.next/**', 'out/**', 'build/**', 'next-env.d.ts', '.claude/**']),
]);
