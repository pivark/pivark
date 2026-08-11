<script lang="ts" setup>
import PvAdminGridLoadingSkeleton from '#/components/pivark/PvAdminGridLoadingSkeleton.vue';
import PvAdminListBatchBar from '#/components/pivark/PvAdminListBatchBar.vue';
import WeappPluginShell from '#/components/pivark/weapp/WeappPluginShell.vue';

import { useDocCommentList } from './list/use-comment-list';

defineOptions({ name: 'WeappDocCommentList' });

const { Grid, batchApprove, batchReject, reviewAllPending, reviewing, selectedCount } =
  useDocCommentList();
</script>

<template>
  <WeappPluginShell plugin-id="doc_comment" nav-key="list">
    <div class="mb-3">
      <el-button :loading="reviewing" type="success" plain @click="reviewAllPending">
        一键通过全部待审
      </el-button>
    </div>
    <Grid table-title="评论管理">
      <template #loading>
        <PvAdminGridLoadingSkeleton fill />
      </template>
      <template #empty>
        <el-empty description="暂无评论">
          <el-button type="primary" @click="$router.push('/weapp/host/doc_comment/settings')">
            去基础设置
          </el-button>
        </el-empty>
      </template>
      <template #bottom>
        <PvAdminListBatchBar :count="selectedCount">
          <el-button
            :aria-label="`批量通过 ${selectedCount} 条评论`"
            :disabled="selectedCount === 0"
            :loading="reviewing"
            type="success"
            @click="batchApprove"
          >
            批量通过
          </el-button>
          <el-button
            :aria-label="`批量驳回 ${selectedCount} 条评论`"
            :disabled="selectedCount === 0"
            :loading="reviewing"
            type="warning"
            @click="batchReject"
          >
            批量驳回
          </el-button>
        </PvAdminListBatchBar>
      </template>
    </Grid>
  </WeappPluginShell>
</template>
