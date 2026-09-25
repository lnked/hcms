export type HcmsClientOptions = {
    baseUrl: string;
    token?: string;
    fetch?: typeof fetch;
};
export type ListMeta = {
    page: number;
    limit: number;
    total: number;
    totalPages: number;
};
export type ListResult<T> = {
    data: T[];
    meta: ListMeta;
};
export declare class HcmsError extends Error {
    readonly status: number;
    readonly code?: string | undefined;
    constructor(message: string, status: number, code?: string | undefined);
}
export declare function createClient(options: HcmsClientOptions): {
    list: <T = Record<string, unknown>>(slug: string, params?: Record<string, string | number | undefined>) => Promise<ListResult<T>>;
    get: <T = Record<string, unknown>>(slug: string, id: number) => Promise<T>;
    create: <T = Record<string, unknown>>(slug: string, body: Record<string, unknown>) => Promise<T>;
    update: <T = Record<string, unknown>>(slug: string, id: number, body: Record<string, unknown>) => Promise<T>;
    remove: (slug: string, id: number) => Promise<void>;
    preview: <T = Record<string, unknown>>(token: string) => Promise<{
        resourceId: number;
        slug: string;
        entry: T;
        expiresAt: number;
    }>;
    openapi: () => Promise<unknown>;
};
export type HcmsClient = ReturnType<typeof createClient>;
