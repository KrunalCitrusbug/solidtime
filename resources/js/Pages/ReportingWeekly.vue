<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import WeeklyReportingOverview from '@/Components/Common/Reporting/WeeklyReportingOverview.vue';
</script>

<template>
    <AppLayout title="Weekly Report" data-testid="weekly_reporting_view" class="overflow-hidden">
        <WeeklyReportingOverview></WeeklyReportingOverview>
    </AppLayout>
</template>
