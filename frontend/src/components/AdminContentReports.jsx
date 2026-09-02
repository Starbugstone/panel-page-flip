import { useCallback, useEffect, useRef, useState } from "react";

import { ContentReportReview } from "@/components/admin/content-reports/ContentReportReview";
import { ContentReportsQueue } from "@/components/admin/content-reports/ContentReportsQueue";
import { api } from "@/lib/api";
import { contentReportReviewPayload, createContentReportReview } from "@/lib/content-report-review";

/** Coordinates queue loading with the currently open report review. */
export function AdminContentReports() {
  const [reports, setReports] = useState([]);
  const [statuses, setStatuses] = useState([]);
  const [categories, setCategories] = useState([]);
  const [actions, setActions] = useState([]);
  const [filters, setFilters] = useState({ status: "", category: "", from: "", to: "" });
  const [selected, setSelected] = useState(null);
  const [review, setReview] = useState(null);
  const [search, setSearch] = useState("");
  const [error, setError] = useState(null);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [saving, setSaving] = useState(false);
  const deepLinkOpened = useRef(false);

  const setDetail = useCallback((report) => {
    setSelected(report);
    setReview(createContentReportReview(report));
  }, []);

  const loadDetail = useCallback(async (id, { query = "", failure }) => {
    setLoadingDetail(true);
    setError(null);
    try {
      const suffix = query ? `?q=${encodeURIComponent(query)}` : "";
      return (await api.get(`/api/admin/content-reports/${id}${suffix}`)).report;
    } catch (loadError) {
      setError(loadError.message || failure);
      return null;
    } finally {
      setLoadingDetail(false);
    }
  }, []);

  const open = useCallback(async (summary) => {
    const report = await loadDetail(summary.id, { failure: "The report details could not be loaded." });
    if (!report) return;
    setDetail(report);
    setSearch("");
  }, [loadDetail, setDetail]);

  useEffect(() => {
    let cancelled = false;
    const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value));
    api.get(`/api/admin/content-reports${query.size ? `?${query}` : ""}`)
      .then((data) => {
        if (cancelled) return;
        setReports(data.reports || []);
        setStatuses(data.statuses || []);
        setCategories(data.categories || []);
        setActions(data.actions || []);
        setError(null);

        // Notification links open by id even if the active queue filters do
        // not include that report.
        if (!deepLinkOpened.current) {
          const requestedId = Number(new URLSearchParams(window.location.search).get("report"));
          if (Number.isInteger(requestedId) && requestedId > 0) {
            deepLinkOpened.current = true;
            void open({ id: requestedId });
          }
        }
      })
      .catch((loadError) => {
        if (!cancelled) setError(loadError.message || "Content reports could not be loaded.");
      });

    return () => {
      cancelled = true;
    };
  }, [filters, open]);

  const searchCandidates = async () => {
    if (!selected) return;
    const report = await loadDetail(selected.id, {
      query: search.trim(),
      failure: "Target candidates could not be loaded.",
    });
    if (!report) return;
    setSelected(report);
    setReview((current) => ({ ...current, pendingTarget: undefined, action: "none" }));
  };

  const chooseTarget = (candidate) => {
    setReview((current) => ({
      ...current,
      pendingTarget: { type: candidate.type, id: candidate.id },
      action: "none",
    }));
  };

  const unlinkTarget = () => {
    setReview((current) => ({ ...current, pendingTarget: null, action: "none", notifyOwner: false }));
  };

  const save = async () => {
    if (!selected || !review) return;
    setSaving(true);
    setError(null);
    try {
      const response = await api.patch(
        `/api/admin/content-reports/${selected.id}`,
        contentReportReviewPayload(review)
      );
      setDetail(response.report);
      setReports((current) => current.map((item) => item.id === response.report.id ? {
        ...item,
        status: response.report.status,
        reviewedAt: response.report.reviewedAt,
        linkedTarget: response.report.linkedTarget,
      } : item));
    } catch (saveError) {
      setError(saveError.message || "The review could not be saved.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6">
      <ContentReportsQueue
        reports={reports}
        statuses={statuses}
        categories={categories}
        filters={filters}
        error={error}
        loadingDetail={loadingDetail}
        onFiltersChange={setFilters}
        onOpen={open}
      />

      {selected && review && (
        <ContentReportReview
          report={selected}
          review={review}
          statuses={statuses}
          actions={actions}
          search={search}
          loadingDetail={loadingDetail}
          saving={saving}
          onReviewChange={setReview}
          onSearchChange={setSearch}
          onSearch={searchCandidates}
          onChooseTarget={chooseTarget}
          onUnlinkTarget={unlinkTarget}
          onSave={save}
        />
      )}
    </div>
  );
}
