import { createContext, useContext } from 'react';

export type DemoRole = 'student' | 'teacher' | 'admin' | 'super_admin';

export type DemoRoleContextValue = {
    role: DemoRole;
    setRole: (role: DemoRole) => void;
};

export const DemoRoleContext = createContext<DemoRoleContextValue | null>(null);

export function useDemoRole(): DemoRoleContextValue {
    const context = useContext(DemoRoleContext);

    if (!context) {
        throw new Error('useDemoRole must be used inside DemoRoleContext.Provider');
    }

    return context;
}
