<?php
declare(strict_types=1);

/** The 19 LHV afdelingen (lhv.nl/afdelingen, Nov 2026), in LHV numbering order. */
const LHV_REGIONS = [
    'friesland' => 'Friesland',
    'groningen' => 'Groningen',
    'drenthe' => 'Drenthe',
    'zwolle-flevo-vechtdal' => 'Zwolle / Flevo / Vechtdal',
    'twente' => 'Twente',
    'gelre-ijssel' => 'Gelre-IJssel',
    'gelderse-rivieren' => 'De Gelderse Rivieren',
    'nijmegen' => 'Nijmegen e.o.',
    'brabant-oost' => 'Brabant Oost',
    'limburg' => 'Limburg',
    'brabant-midden-west' => 'Brabant Midden West',
    'zeeland-zhz' => 'Zeeland / Zuid-Holland Zuid',
    'midden-nederland' => 'Midden-Nederland',
    'rotterdam' => 'Rotterdam',
    'west' => 'West',
    'holland-midden-west' => 'Holland Midden West',
    'amsterdam-almere' => 'Amsterdam / Almere',
    'noord-holland-midden' => 'Noord-Holland Midden',
    'noord-holland-noord' => 'Noord-Holland Noord',
];

function region_label(?string $key): string
{
    return $key === null ? 'Geen regio' : (LHV_REGIONS[$key] ?? 'Onbekende regio');
}

/** @return string|null valid region key, or null when absent/invalid. */
function parse_region(mixed $value): ?string
{
    return is_string($value) && array_key_exists($value, LHV_REGIONS) ? $value : null;
}
