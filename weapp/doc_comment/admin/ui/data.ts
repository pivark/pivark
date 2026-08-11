import type { CommentRow } from '@weapp-doc-comment/api/comment';

import type { PvFormSchema } from '#/adapter/form';
import type {
  OnActionClickFn,
  VxeTableGridColumns,
} from '#/adapter/vxe-table';

import { fetchCommentListApi } from '@weapp-doc-comment/api/comment';
import {
  PV_COL_DATE,
  PV_COL_FLAG,
  PV_COL_ID,
  PV_COL_OPS,
  PV_COL_TEXT,
} from '#/composables/pv-admin-list-column-presets';
import { usePvAdminListSearchFormOptions } from '#/composables/pv-admin-list-form-layout';

export type { CommentRow };

const COMMENT_STATUS_OPTIONS = [
  { label: '待审', value: '0' },
  { label: '通过', value: '1' },
  { label: '驳回', value: '2' },
];

export function useDocCommentGridFormSchema(): PvFormSchema[] {
  return [
    {
      component: 'Input',
      fieldName: 'keyword',
      label: '关键词',
      componentProps: {
        clearable: true,
        placeholder: '内容 / 用户名 / 文档 ID',
      },
    },
    {
      component: 'Select',
      fieldName: 'status',
      label: '审核状态',
      componentProps: {
        clearable: true,
        options: COMMENT_STATUS_OPTIONS,
        placeholder: '全部',
      },
    },
  ];
}

export function useDocCommentListGridFormOptions() {
  return usePvAdminListSearchFormOptions(useDocCommentGridFormSchema());
}

export function mapCommentListQueryParams(formValues: Record<string, unknown>) {
  const status = formValues.status;
  return {
    keyword: String(formValues.keyword ?? '').trim() || undefined,
    status:
      status === '' || status === null || status === undefined
        ? ''
        : Number(status),
  };
}

export function useDocCommentGridColumns(
  onActionClick: OnActionClickFn<CommentRow>,
): VxeTableGridColumns<CommentRow> {
  return [
    { type: 'checkbox', width: 48 },
    {
      field: 'id',
      title: 'ID',
      width: 72,
      ...PV_COL_ID,
    },
    {
      field: 'document_id',
      title: '文档',
      width: 88,
      ...PV_COL_ID,
    },
    {
      field: 'username',
      title: '用户',
      width: 120,
      ...PV_COL_TEXT,
    },
    {
      field: 'content',
      title: '内容',
      minWidth: 200,
      ...PV_COL_TEXT,
    },
    {
      field: 'status',
      title: '状态',
      width: 88,
      ...PV_COL_FLAG,
      cellRender: {
        name: 'CellTag',
        options: [
          { type: 'warning', label: '待审', value: 0 },
          { type: 'success', label: '通过', value: 1 },
          { type: 'danger', label: '驳回', value: 2 },
        ],
      },
    },
    {
      field: 'created_at',
      title: '时间',
      width: 170,
      ...PV_COL_DATE,
    },
    {
      ...PV_COL_OPS,
      cellRender: {
        attrs: {
          nameField: 'content',
          nameTitle: '评论',
          onClick: onActionClick,
          skipDeleteConfirm: true,
          variant: 'capsule',
        },
        name: 'CellOperation',
        options: [
          { code: 'approve', text: '通过', type: 'success' },
          { code: 'reject', text: '驳回', type: 'warning' },
          'delete',
        ],
      },
      field: 'operation',
      fixed: 'right',
      showOverflow: false,
      title: '操作',
      width: 200,
    },
  ];
}

export async function fetchCommentListPage(
  page: { currentPage: number; pageSize: number },
  formValues: Record<string, unknown>,
) {
  const mapped = mapCommentListQueryParams(formValues);
  const result = await fetchCommentListApi({
    keyword: mapped.keyword,
    page: page.currentPage,
    pageSize: page.pageSize,
    status: mapped.status as number | '',
  });
  return {
    items: result.list,
    total: result.total,
  };
}
