export type PlatformAuditEntry = {
    id: number;
    event: string;
    company: string | null;
    user: string | null;
    subject: string | null;
    context: Record<string, unknown>;
    occurred_at: string;
};
