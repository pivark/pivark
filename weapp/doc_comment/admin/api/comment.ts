import {
  pivarkRestListPayload,
  type PivarkRestEnvelope,
} from '#/api/pivark-rest';
import { resolveAdminRestUrl } from '#/api/admin-rest-url';
import { requestClient } from '#/api/request';

export type CommentRow = Record<string, unknown> & {
  id?: number;
  document_id?: number;
  document_title?: string;
  username?: string;
  content?: string;
  status?: number;
  status_label?: string;
  created_at?: string;
};

export async function fetchCommentListApi(params: {
  document_id?: number;
  keyword?: string;
  page?: number;
  pageSize?: number;
  status?: number | '';
} = {}) {
  const body = await requestClient.get<PivarkRestEnvelope>(
    resolveAdminRestUrl('/weapp/doc_comment/list'),
    {
      params: {
        page: params.page ?? 1,
        limit: params.pageSize ?? 20,
        keyword: params.keyword?.trim() || undefined,
        document_id: params.document_id || undefined,
        status: params.status === '' ? undefined : params.status,
      },
      responseReturn: 'body',
      withCredentials: true,
    },
  );

  const payload = pivarkRestListPayload(body);
  return {
    list: payload.list as CommentRow[],
    total: payload.total,
  };
}
