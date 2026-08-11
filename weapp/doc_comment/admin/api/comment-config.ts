import { pivarkRestMutationData, type PivarkRestEnvelope } from '#/api/pivark-rest';
import { resolveAdminRestUrl } from '#/api/admin-rest-url';
import {requestClient} from '#/api/request';
import { usePivarkStore } from '#/store/pivark';

export type CommentLevelRow = {
  can_comment: number;
  level_name: string;
  member_level_id: number;
  need_review: number;
};

export async function saveCommentConfigApi(
  cfg: {
    comment_guest_allowed: string;
    comment_open: string;
    comment_page_size: string;
    comment_sensitive_mode: string;
    comment_sensitive_words: string;
  },
  levels: CommentLevelRow[],
) {
  const pivark = usePivarkStore();
  const form = new URLSearchParams();

  Object.entries(pivark.appendCsrf({})).forEach(([key, value]) => {
    form.append(key, String(value));
  });

  if (cfg.comment_open === '1') {
    form.append('comment_open', '1');
  }
  if (cfg.comment_guest_allowed === '1') {
    form.append('comment_guest_allowed', '1');
  }
  form.append('comment_page_size', cfg.comment_page_size);
  form.append('comment_sensitive_mode', cfg.comment_sensitive_mode);
  form.append('comment_sensitive_words', cfg.comment_sensitive_words);

  levels.forEach((row, index) => {
    form.append(
      `levels[${index}][member_level_id]`,
      String(row.member_level_id),
    );
    if (row.can_comment === 1) {
      form.append(`levels[${index}][can_comment]`, '1');
    }
    if (row.need_review === 1) {
      form.append(`levels[${index}][need_review]`, '1');
    }
  });

  const body = await requestClient.post<PivarkRestEnvelope>(resolveAdminRestUrl('/weapp/doc_comment/configSave'),
    form,
    {
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      responseReturn: 'body',
      withCredentials: true,
    },
  );

  return pivarkRestMutationData(body);
}
