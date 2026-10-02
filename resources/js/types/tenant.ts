export type CompanyBrief = {
    id: number;
    name: string;
    code: string | null;
    base_currency: string;
};

export type Tenant = {
    current: CompanyBrief | null;
    companies: CompanyBrief[];
};

export type Flash = {
    success?: string | null;
    error?: string | null;
};
