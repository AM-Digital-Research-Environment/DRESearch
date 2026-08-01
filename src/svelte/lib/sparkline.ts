/** Two explicit association counts; bars avoid implying a time-series interpolation. */
export function associationSeries(items = 0, publications = 0): number[] {
  return [Math.max(0, items), Math.max(0, publications)];
}
