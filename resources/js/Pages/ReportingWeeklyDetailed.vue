<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import WeeklyDetailedReportOverview from '@/Components/Common/Reporting/WeeklyDetailedReportOverview.vue';
</script>

<template>
    <AppLayout
        title="Weekly Detailed Report"
        data-testid="weekly_detailed_reporting_view"
        class="overflow-hidden">
        <WeeklyDetailedReportOverview></WeeklyDetailedReportOverview>
    </AppLayout>
</template>
