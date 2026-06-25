<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import type { Organization, User } from '@/types/models';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
} from '@/packages/ui/src';
import {
    UserCircleIcon,
    KeyIcon,
    ArrowLeftOnRectangleIcon,
    ChatBubbleLeftRightIcon,
} from '@heroicons/vue/24/solid';
import { EllipsisVerticalIcon } from '@heroicons/vue/20/solid';
import { openFeedback } from '@/utils/feedback';
import { Button } from '@/packages/ui/src/Buttons';

const page = usePage<{
    has_services_extension?: boolean;
    jetstream: {
        canCreateTeams: boolean;
        hasTeamFeatures: boolean;
        managesProfilePhotos: boolean;
        hasApiFeatures: boolean;
    };
    auth: {
        user: User & {
            all_teams: Organization[];
        };
    };
}>();

function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <div
        class="border-t border-default-background-separator pt-3 flex items-center gap-2.5 min-w-0">
        <img
            class="h-9 w-9 rounded-full object-cover shrink-0"
            :src="page.props.auth.user.profile_photo_url"
            :alt="page.props.auth.user.name" />
        <div class="flex-1 min-w-0">
            <div class="text-sm font-medium text-text-primary truncate">
                {{ page.props.auth.user.name }}
            </div>
            <div class="text-xs text-text-tertiary truncate">
                {{ page.props.auth.user.email }}
            </div>
        </div>
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon"
                    class="h-8 w-8 shrink-0"
                    aria-label="Account menu"
                    data-testid="current_user_button">
                    <EllipsisVerticalIcon class="h-5 w-5 text-icon-default" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-48">
                <DropdownMenuLabel>Manage Account</DropdownMenuLabel>

                <DropdownMenuItem as-child>
                    <Link
                        :href="route('profile.show')"
                        class="inline-flex items-center gap-2.5 w-full">
                        <UserCircleIcon class="w-5 h-5 text-icon-default" />
                        <span>Profile Settings</span>
                    </Link>
                </DropdownMenuItem>

                <DropdownMenuItem v-if="page.props.jetstream.hasApiFeatures" as-child>
                    <Link
                        :href="route('api-tokens.index')"
                        class="inline-flex items-center gap-2.5 w-full">
                        <KeyIcon class="w-5 h-5 text-icon-default" />
                        <span>API Tokens</span>
                    </Link>
                </DropdownMenuItem>

                <DropdownMenuItem v-if="page.props.has_services_extension" as-child>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2.5 w-full"
                        @click="openFeedback">
                        <ChatBubbleLeftRightIcon class="w-5 h-5 text-icon-default" />
                        <span>Feedback</span>
                    </button>
                </DropdownMenuItem>

                <form class="w-full" @submit.prevent="logout">
                    <DropdownMenuItem as-child class="inline-flex items-center gap-2.5 w-full">
                        <button type="submit" data-testid="logout_button">
                            <ArrowLeftOnRectangleIcon class="w-5 h-5 text-icon-default" />
                            <span>Log Out</span>
                        </button>
                    </DropdownMenuItem>
                </form>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
