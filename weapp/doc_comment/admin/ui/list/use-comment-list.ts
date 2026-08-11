import type { CommentRow } from '../data';

import type { VxeTableGridOptions } from '#/adapter/vxe-table';

import { ref, shallowRef } from 'vue';

import { ElMessage } from 'element-plus';

import { pivarkMutation } from '#/api/pivark/core/mutation';
import { confirmAdminRowDelete } from '#/composables/use-admin-batch-actions';
import {
  PV_ADMIN_LIST_GRID_CLASS,
  PV_ADMIN_LIST_GRID_OPTIONS,
  usePvAdminListGrid,
} from '#/composables/use-pv-admin-list-grid';

import {
  fetchCommentListPage,
  useDocCommentGridColumns,
  useDocCommentListGridFormOptions,
} from '../data';

export function useDocCommentList() {
  const selectedCount = ref(0);
  const reviewing = ref(false);

  const gridApiRef = shallowRef<
    ReturnType<typeof usePvAdminListGrid<CommentRow>>['gridApi'] | null
  >(null);

  function getGridApi() {
    return gridApiRef.value!;
  }

  function selectedIds(): number[] {
    return (
      (getGridApi().grid?.getCheckboxRecords?.() ?? []) as CommentRow[]
    )
      .map((row) => Number(row.id))
      .filter((id) => id > 0);
  }

  function syncSelectedCount() {
    const next = selectedIds().length;
    if (selectedCount.value !== next) {
      selectedCount.value = next;
    }
  }

  async function refreshGrid() {
    await getGridApi().query();
    syncSelectedCount();
  }

  async function review(ids: number[], status: number) {
    if (ids.length === 0) {
      ElMessage.warning('请先选择评论');
      return;
    }
    reviewing.value = true;
    try {
      await pivarkMutation('/weapp/doc_comment/review', { ids, status });
      ElMessage.success('已更新');
      await refreshGrid();
    } finally {
      reviewing.value = false;
    }
  }

  async function handleRowDelete(row: CommentRow) {
    await confirmAdminRowDelete({
      message: '确定删除该评论？',
      title: '删除',
      action: async () => {
        await pivarkMutation('/weapp/doc_comment/delete', { id: Number(row.id) });
        await refreshGrid();
      },
      successMessage: '已删除',
    });
  }

  function onActionClick(e: { code: string; row: CommentRow }) {
    const id = Number(e.row.id);
    if (id < 1) {
      return;
    }
    if (e.code === 'approve') {
      void review([id], 1);
      return;
    }
    if (e.code === 'reject') {
      void review([id], 2);
      return;
    }
    if (e.code === 'delete') {
      void handleRowDelete(e.row);
    }
  }

  const { Grid, gridApi } = usePvAdminListGrid<CommentRow>(() => ({
    formOptions: useDocCommentListGridFormOptions(),
    gridClass: PV_ADMIN_LIST_GRID_CLASS,
    gridEvents: {
      checkboxAll: syncSelectedCount,
      checkboxChange: syncSelectedCount,
    },
    gridOptions: {
      ...PV_ADMIN_LIST_GRID_OPTIONS,
      columns: useDocCommentGridColumns(onActionClick),
      checkboxConfig: {
        highlight: true,
        reserve: true,
      },
      proxyConfig: {
        ...PV_ADMIN_LIST_GRID_OPTIONS.proxyConfig,
        ajax: {
          query: async ({ page }, formValues) => {
            const result = await fetchCommentListPage(page, formValues ?? {});
            syncSelectedCount();
            return {
              items: result.items,
              total: result.total,
            };
          },
        },
      },
      rowConfig: {
        keyField: 'id',
      },
    } as VxeTableGridOptions,
  }));
  gridApiRef.value = gridApi;

  async function reviewAllPending() {
    reviewing.value = true;
    try {
      const data = await pivarkMutation<{ updated?: number }>(
        '/weapp/doc_comment/reviewAll',
        {},
      );
      const n = Number(data?.updated ?? 0);
      ElMessage.success(n > 0 ? `已通过 ${n} 条待审评论` : '当前没有待审评论');
      await refreshGrid();
    } finally {
      reviewing.value = false;
    }
  }

  return {
    Grid,
    batchApprove: () => review(selectedIds(), 1),
    batchReject: () => review(selectedIds(), 2),
    reviewAllPending,
    reviewing,
    selectedCount,
  };
}
