import { describe, expect, it } from 'vitest';
import { matchesSearch, normalizeSearch } from './searchNormalize';

describe('normalizeSearch', () => {
    it('folds case and French accents', () => {
        expect(normalizeSearch('Éditeurs d’Oeuvres')).toBe(
            normalizeSearch('editeurs d’oeuvres'),
        );
        expect(normalizeSearch('  Béjaïa  ')).toBe('bejaia');
        expect(normalizeSearch('ALGÉRIE')).toBe('algerie');
    });

    it('folds Arabic hamza forms, ta marbuta, alif maqsura, diacritics and tatweel', () => {
        expect(normalizeSearch('أدرار')).toBe(normalizeSearch('ادرار'));
        expect(normalizeSearch('الإسكندرية')).toBe(normalizeSearch('الاسكندريه'));
        expect(normalizeSearch('مستشفى')).toBe(normalizeSearch('مستشفي'));
        expect(normalizeSearch('مُؤَلِّف')).toBe(normalizeSearch('مؤلف'));
        expect(normalizeSearch('الجـــزائر')).toBe(normalizeSearch('الجزائر'));
    });
});

describe('matchesSearch', () => {
    it('matches an empty query against anything', () => {
        expect(matchesSearch('Tizi Ouzou', '')).toBe(true);
        expect(matchesSearch('Tizi Ouzou', '   ')).toBe(true);
    });

    it('matches every word of the query, in any order', () => {
        expect(matchesSearch('15 - Tizi Ouzou', 'ouzou tizi')).toBe(true);
        expect(matchesSearch('15 - Tizi Ouzou', 'tizi oran')).toBe(false);
    });

    it('is accent- and case-insensitive in both scripts', () => {
        expect(matchesSearch('Sétif', 'SETIF')).toBe(true);
        expect(matchesSearch('Compositeur de musique', 'compos')).toBe(true);
        expect(matchesSearch('أم البواقي', 'ام البواقي')).toBe(true);
        expect(matchesSearch('غرداية', 'غردايه')).toBe(true);
        expect(matchesSearch('وهران', 'تلمسان')).toBe(false);
    });
});
