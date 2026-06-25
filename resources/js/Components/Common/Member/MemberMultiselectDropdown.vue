<script setup lang="ts">
import MultiselectDropdown from '@/packages/ui/src/Input/MultiselectDropdown.vue';
import { useMembersQuery } from '@/utils/useMembersQuery';
import type { Member } from '@/packages/api/src';
import { formatMemberNameWithEmail } from '@/utils/format';
import { computed } from 'vue';

const props = defineProps<{
    members?: Member[];
}>();

const { members: allMembers } = useMembersQuery();
const memberList = computed(() =>
    (props.members ?? allMembers.value).filter((member) => member.is_placeholder === false)
);

function getKeyFromItem(item: Member) {
    return item.id;
}

function getNameForItem(item: Member) {
    return formatMemberNameWithEmail(item);
}

const emit = defineEmits<{
    submit: [];
}>();
</script>

<template>
    <MultiselectDropdown
        search-placeholder="Search for a Member..."
        :items="memberList"
        :get-key-from-item="getKeyFromItem"
        :get-name-for-item="getNameForItem"
        @submit="emit('submit')">
        <template #trigger>
            <slot name="trigger"></slot>
        </template>
    </MultiselectDropdown>
</template>
