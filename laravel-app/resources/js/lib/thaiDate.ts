const thaiMonths = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const fullThaiMonths = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

/** Format a civil date without timezone conversion or modifying the source value. */
export function formatThaiDisplayDate(value: string | null | undefined): string {
    const raw = (value ?? '').trim().replace(/[๐-๙]/g, (digit) => String('๐๑๒๓๔๕๖๗๘๙'.indexOf(digit)));
    let day: number;
    let month: number;
    let year: number;
    const yearFirst = raw.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})(?=$|[T\s])/)
        ?? raw.match(/^(\d{4})(\d{2})(\d{2})$/);
    const dayFirst = raw.match(/^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$/);
    const namedMonth = raw.match(/^(\d{1,2})\s+(\S+)\s+(\d{4})$/);
    if (yearFirst) {
        [, year, month, day] = yearFirst.map(Number);
    } else if (dayFirst) {
        [, day, month, year] = dayFirst.map(Number);
    } else if (namedMonth) {
        day = Number(namedMonth[1]);
        month = thaiMonths.indexOf(namedMonth[2]) + 1 || fullThaiMonths.indexOf(namedMonth[2]) + 1;
        year = Number(namedMonth[3]);
    } else {
        return '-';
    }
    const christianYear = year >= 2400 ? year - 543 : year;
    const buddhistYear = year >= 2400 ? year : year + 543;
    const leap = christianYear % 4 === 0 && (christianYear % 100 !== 0 || christianYear % 400 === 0);
    const daysInMonth = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    if (year <= 0 || month < 1 || month > 12 || day < 1 || day > daysInMonth[month - 1]) return '-';

    return `${day} ${thaiMonths[month - 1]} ${buddhistYear}`;
}
