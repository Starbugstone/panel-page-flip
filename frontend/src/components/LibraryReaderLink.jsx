import { Link, useLocation } from "react-router-dom";

/** A reader link that remembers the exact library view it was opened from. */
export function LibraryReaderLink({ comicId, ...props }) {
  const location = useLocation();
  const state = location.pathname === "/dashboard"
    ? { libraryReturnTo: `${location.pathname}${location.search}` }
    : undefined;

  return <Link to={`/read/${comicId}`} state={state} {...props} />;
}
