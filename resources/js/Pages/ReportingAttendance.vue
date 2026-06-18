<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import AttendanceReportOverview from '@/Components/Common/Reporting/AttendanceReportOverview.vue';
</script>

<template>
    <AppLayout title="Attendance Report" data-testid="attendance_reporting_view" class="overflow-hidden">
        <AttendanceReportOverview></AttendanceReportOverview>
    </AppLayout>
</template>
