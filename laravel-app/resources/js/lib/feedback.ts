const commonOptions = {
    toast: true,
    position: 'top-end' as const,
    timer: 3_000,
    timerProgressBar: true,
    showConfirmButton: false,
    showCloseButton: false,
    customClass: {
        popup: '!rounded-2xl !border !border-slate-200 !bg-white !px-4 !py-3 !shadow-[0_18px_55px_rgb(15_23_42_/_0.16)]',
        title: '!m-0 !text-sm !font-bold !leading-6 !text-slate-950',
        timerProgressBar: '!bg-emerald-600',
    },
};

export function showSuccessAlert(message = 'บันทึกข้อมูลสำเร็จ'): void {
    void import('sweetalert2').then(({ default: Swal }) => Swal.fire({
        ...commonOptions,
        icon: 'success',
        title: message,
    }));
}

export function showErrorAlert(message = 'ไม่สามารถบันทึกข้อมูลได้ กรุณาลองใหม่'): void {
    void import('sweetalert2').then(({ default: Swal }) => Swal.fire({
        ...commonOptions,
        icon: 'error',
        title: message,
        customClass: {
            ...commonOptions.customClass,
            timerProgressBar: '!bg-rose-600',
        },
    }));
}
