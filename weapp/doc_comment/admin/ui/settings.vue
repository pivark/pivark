<script lang="ts" setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import { ElMessage } from 'element-plus';

import {
  saveCommentConfigApi,
  type CommentLevelRow,
} from '@weapp-doc-comment/api/comment-config';
import { fetchSpaWeappPluginMetaApi } from '#/api/pivark/core/spa';
import type { CommentMeta } from '#/api/pivark/core/spa-meta-types';
import { useWeappPluginId } from '#/composables/use-weapp-plugin-settings';
import WeappPluginKpiRow from '#/components/pivark/weapp/WeappPluginKpiRow.vue';
import WeappPluginPageCard from '#/components/pivark/weapp/WeappPluginPageCard.vue';
import WeappPluginShell from '#/components/pivark/weapp/WeappPluginShell.vue';
import PvModuleSection from '#/components/pivark/shell/PvModuleSection.vue';

defineOptions({ name: 'WeappDocCommentSettings' });

const router = useRouter();
const pluginId = useWeappPluginId();
const loading = ref(false);
const saving = ref(false);

const cfg = reactive({
  comment_open: '1',
  comment_guest_allowed: '0',
  comment_page_size: '10',
  comment_sensitive_mode: 'replace',
  comment_sensitive_words: '',
});

const levels = ref<CommentLevelRow[]>([]);
const stats = ref({ total: 0, pending: 0, approved: 0 });

const kpiItems = computed(() => [
  { label: '评论总数', value: stats.value.total },
  { label: '待审核', value: stats.value.pending },
  { label: '已通过', value: stats.value.approved },
]);

const sensitiveModes = [
  { label: '替换为 **', value: 'replace' },
  { label: '转待审核', value: 'review' },
  { label: '禁止发表', value: 'block' },
];

function flagOn(key: 'comment_guest_allowed' | 'comment_open') {
  return cfg[key] === '1';
}

function setFlag(key: 'comment_guest_allowed' | 'comment_open', on: boolean) {
  cfg[key] = on ? '1' : '0';
}

function toggleLevel(
  row: CommentLevelRow,
  field: 'can_comment' | 'need_review',
  on: boolean,
) {
  row[field] = on ? 1 : 0;
}

async function loadMeta() {
  loading.value = true;
  try {
    const meta = await fetchSpaWeappPluginMetaApi<CommentMeta>(pluginId.value);
    const c = meta.cfg ?? {};
    cfg.comment_open = String(c.comment_open ?? '1');
    cfg.comment_guest_allowed = String(c.comment_guest_allowed ?? '0');
    cfg.comment_page_size = String(c.comment_page_size ?? '10');
    cfg.comment_sensitive_mode = String(c.comment_sensitive_mode ?? 'replace');
    cfg.comment_sensitive_words = String(c.comment_sensitive_words ?? '');
    levels.value = (meta.levels ?? []) as CommentLevelRow[];
    stats.value = {
      total: Number(meta.stats?.total ?? 0),
      pending: Number(meta.stats?.pending ?? 0),
      approved: Number(meta.stats?.approved ?? 0),
    };
  } finally {
    loading.value = false;
  }
}

async function handleSave() {
  saving.value = true;
  try {
    await saveCommentConfigApi({ ...cfg }, levels.value);
    ElMessage.success('保存成功');
    await loadMeta();
  } finally {
    saving.value = false;
  }
}

function goList() {
  router.push('/weapp/host/doc_comment/list');
}

onMounted(loadMeta);
</script>

<template>
  <WeappPluginShell :plugin-id="pluginId" nav-key="settings">
    <WeappPluginPageCard :loading="loading" title="基础设置">
      <template #actions>
        <el-button @click="goList">评论管理</el-button>
        <el-button :loading="saving" type="primary" @click="handleSave">
          保存配置
        </el-button>
      </template>

      <WeappPluginKpiRow :items="kpiItems" />

      <PvModuleSection title="评论开关">
        <el-form label-position="top">
          <el-form-item label="评论功能">
            <el-switch
              :model-value="flagOn('comment_open')"
              @update:model-value="setFlag('comment_open', !!$event)"
            />
          </el-form-item>
          <el-form-item label="游客评论">
            <el-switch
              :model-value="flagOn('comment_guest_allowed')"
              active-text="允许"
              inactive-text="禁止"
              @update:model-value="setFlag('comment_guest_allowed', !!$event)"
            />
            <p class="pv-weapp-hint mt-1">关闭时须登录会员方可评论</p>
          </el-form-item>
          <el-form-item label="每页条数">
            <el-input-number
              :model-value="Number(cfg.comment_page_size) || 10"
              :min="5"
              :max="50"
              @update:model-value="
                cfg.comment_page_size = String($event ?? 10)
              "
            />
          </el-form-item>
        </el-form>
      </PvModuleSection>

      <PvModuleSection title="敏感词">
        <el-form label-position="top">
          <el-form-item label="处理方式">
            <el-radio-group v-model="cfg.comment_sensitive_mode">
              <el-radio
                v-for="opt in sensitiveModes"
                :key="opt.value"
                :value="opt.value"
              >
                {{ opt.label }}
              </el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="词库">
            <el-input
              v-model="cfg.comment_sensitive_words"
              type="textarea"
              :rows="4"
              placeholder="多个词用逗号或空格分隔"
            />
          </el-form-item>
        </el-form>
      </PvModuleSection>

      <PvModuleSection title="会员等级权限">
        <el-table :data="levels" row-key="member_level_id">
          <el-table-column label="等级" prop="level_name" min-width="140" />
          <el-table-column label="允许评论" width="100" align="center">
            <template #default="scope">
              <el-switch
                v-if="scope?.row"
                :model-value="scope.row.can_comment === 1"
                @update:model-value="
                  toggleLevel(scope.row, 'can_comment', !!$event)
                "
              />
            </template>
          </el-table-column>
          <el-table-column label="须审核" width="100" align="center">
            <template #default="scope">
              <el-switch
                v-if="scope?.row"
                :model-value="scope.row.need_review === 1"
                @update:model-value="
                  toggleLevel(scope.row, 'need_review', !!$event)
                "
              />
            </template>
          </el-table-column>
        </el-table>
      </PvModuleSection>
    </WeappPluginPageCard>
  </WeappPluginShell>
</template>
