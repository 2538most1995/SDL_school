export const LOGIN_TYPE_ORDER = ['student', 'staff'] as const;

export type LoginType = (typeof LOGIN_TYPE_ORDER)[number];

export const DEFAULT_LOGIN_TYPE: LoginType = LOGIN_TYPE_ORDER[0];
