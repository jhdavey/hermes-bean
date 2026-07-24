export const centeredMonthCellCount = 35;

export function centeredMonthGridDays(anchorValue, cellCount = centeredMonthCellCount) {
    if (!Number.isInteger(cellCount) || cellCount < 7 || cellCount % 7 !== 0 || cellCount % 2 === 0) {
        throw new TypeError('Centered month grids require an odd multiple of seven cells.');
    }

    const anchor = anchorValue instanceof Date
        ? new Date(anchorValue.getTime())
        : new Date(anchorValue);
    if (Number.isNaN(anchor.getTime())) {
        throw new TypeError('Centered month grids require a valid anchor date.');
    }

    anchor.setHours(12, 0, 0, 0);
    const centerIndex = Math.floor(cellCount / 2);
    const first = new Date(anchor);
    first.setDate(first.getDate() - centerIndex);

    return Array.from({ length: cellCount }, (_, index) => {
        const day = new Date(first);
        day.setDate(day.getDate() + index);
        return day;
    });
}
