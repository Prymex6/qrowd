import { describe, it, expect } from 'vitest';
import { time, plural } from '@/live';

describe('time()', () => {
    it('formats seconds as minutes and seconds', () => {
        expect(time(187)).toBe('3:07');
        expect(time(60)).toBe('1:00');
        expect(time(59)).toBe('0:59');
    });

    it('pads the seconds with a zero', () => {
        expect(time(65)).toBe('1:05');
        expect(time(3609)).toBe('60:09');
    });

    it('copes with a missing value', () => {
        // Tracks from the schedule have no known duration - the progress bar must
        // not show "NaN:NaN" then.
        expect(time(0)).toBe('0:00');
        expect(time(null)).toBe('0:00');
        expect(time(undefined)).toBe('0:00');
        expect(time(-30)).toBe('0:00');
    });

    it('drops fractions of a second', () => {
        expect(time(90.7)).toBe('1:30');
    });
});

describe('plural()', () => {
    const guests = (n) => `${n} ${plural(n, 'gość', 'goście', 'gości')}`;

    it('picks the Polish form by the number', () => {
        expect(guests(1)).toBe('1 gość');
        expect(guests(2)).toBe('2 goście');
        expect(guests(4)).toBe('4 goście');
        expect(guests(5)).toBe('5 gości');
        expect(guests(0)).toBe('0 gości');
    });

    it('treats the teens as "many" but 22 as "few"', () => {
        expect(guests(12)).toBe('12 gości');
        expect(guests(14)).toBe('14 gości');
        expect(guests(22)).toBe('22 goście');
        expect(guests(112)).toBe('112 gości');
    });
});
