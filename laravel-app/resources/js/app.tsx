import { Component, StrictMode, Suspense, useCallback, useState } from 'react';
import type { ErrorInfo, ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { Button, Card, Spinner } from './components/MaterialUI';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import 'sweetalert2/dist/sweetalert2.min.css';
import { GlobalMutationLoading } from './components/GlobalMutationLoading';
import { MaterialAppProvider, PublicLightMaterialProvider } from './components/MaterialAppProvider';
import { DemoRoleContext, useDemoRole, type DemoRole } from './context/DemoRoleContext';
import { featureRouteCatalog } from './features';
import { PortalLayout } from './layouts/PortalLayout';
import { DashboardHomePage } from './pages/DashboardHomePage';
import { LandingPage } from './pages/LandingPage';
import { LoginPage } from './pages/LoginPage';
import { queryClient } from './query';
import { initializeAppearance } from './lib/appearance';
import { APP_BASE_PATH } from './lib/urls';

initializeAppearance();

function App() {
    const [role, setRoleState] = useState<DemoRole>('student');
    const setRole = useCallback((nextRole: DemoRole) => setRoleState(nextRole), []);

    return (
        <DemoRoleContext.Provider value={{ role, setRole }}>
            <Routes>
                <Route path="/" element={<PublicLightMaterialProvider><LandingPage /></PublicLightMaterialProvider>} />
                <Route path="/login" element={<PublicLightMaterialProvider><LoginPage /></PublicLightMaterialProvider>} />
                <Route element={<PortalLayout />}>
                    <Route path="/app" element={<DashboardHomePage />} />
                    {featureRouteCatalog.map((route) => (
                        <Route
                            key={route.path}
                            path={route.path}
                            element={(
                                <RoleRouteGuard roles={route.roles}>
                                    <Suspense fallback={<RouteLoadingState />}>
                                        {route.element}
                                    </Suspense>
                                </RoleRouteGuard>
                            )}
                        />
                    ))}
                </Route>
                <Route path="*" element={<Navigate to="/" replace />} />
            </Routes>
        </DemoRoleContext.Provider>
    );
}

function RoleRouteGuard({ roles, children }: { roles: DemoRole[]; children: ReactNode }) {
    const { role } = useDemoRole();

    return roles.includes(role) ? children : <Navigate to="/app" replace />;
}

function RouteLoadingState() {
    return (
        <div className="space-y-5" aria-label="กำลังเปิดหน้า">
            <Spinner label="กำลังเปิดหน้า" size="small" className="justify-start" />
            <div className="animate-pulse h-24 rounded-lg bg-slate-200" aria-hidden="true" />
            <div className="grid gap-3 sm:grid-cols-3">
                {[1, 2, 3].map((item) => <div key={item} className="h-32 animate-pulse rounded-lg bg-slate-200" aria-hidden="true" />)}
            </div>
            <div className="h-72 animate-pulse rounded-lg bg-slate-200" aria-hidden="true" />
        </div>
    );
}

class AppErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    componentDidCatch(error: Error, info: ErrorInfo) {
        console.error('Application rendering failed', error, info.componentStack);
    }

    render() {
        if (this.state.failed) {
            return (
                <main className="grid min-h-[100dvh] place-items-center bg-[#f7f8fc] p-6">
                    <Card role="alert" className="w-full max-w-lg p-8 text-center">
                        <h1 className="text-2xl font-bold text-slate-950">เปิดหน้าระบบไม่สำเร็จ</h1>
                        <p className="mt-3 text-sm leading-6 text-slate-600">ระบบเก็บสถานะการเข้าสู่ระบบไว้แล้ว กรุณารีเฟรชไฟล์หน้าเว็บเพื่อเปิดหน้านี้อีกครั้ง</p>
                        <Button appearance="primary" onClick={() => window.location.reload()} className="mt-6">ลองเปิดหน้านี้อีกครั้ง</Button>
                    </Card>
                </main>
            );
        }

        return this.props.children;
    }
}

createRoot(document.getElementById('app')!).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <MaterialAppProvider>
                <GlobalMutationLoading />
                <BrowserRouter basename={APP_BASE_PATH || undefined}>
                    <AppErrorBoundary>
                        <App />
                    </AppErrorBoundary>
                </BrowserRouter>
            </MaterialAppProvider>
        </QueryClientProvider>
    </StrictMode>,
);
