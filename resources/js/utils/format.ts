export function capitalizeFirstLetter(string: string) {
    return string?.charAt(0)?.toUpperCase() + string?.slice(1);
}

export function formatMemberNameWithEmail(member: { name: string; email: string }): string {
    return `${member.name} (${member.email})`;
}
