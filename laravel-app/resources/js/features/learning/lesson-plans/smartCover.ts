export type SmartCoverOptions = {
    title: string;
    level: string;
    levelLabel?: string;
    scopeLabel?: string;
    subject?: string;
    semester?: string;
    districtName?: string;
    teacherName?: string;
};

const levelThemes: Record<string, { bg1: string; bg2: string; bg3: string; accent: string; badgeBg: string }> = {
    '1': { bg1: '#064e3b', bg2: '#047857', bg3: '#065f46', accent: '#a7f3d0', badgeBg: 'rgba(6, 78, 59, 0.85)' },
    '2': { bg1: '#1e3a8a', bg2: '#2563eb', bg3: '#1d4ed8', accent: '#bfdbfe', badgeBg: 'rgba(30, 58, 138, 0.85)' },
    '3': { bg1: '#4c1d95', bg2: '#7c3aed', bg3: '#6d28d9', accent: '#ddd6fe', badgeBg: 'rgba(76, 29, 149, 0.85)' },
};

function wrapText(ctx: CanvasRenderingContext2D, text: string, maxWidth: number): string[] {
    const words = text.split(/\s+/).filter(Boolean);
    if (words.length <= 1) {
        // Thai text often has no spaces between words, chunk by character length
        const lines: string[] = [];
        let current = '';
        for (const char of text) {
            const testLine = current + char;
            if (ctx.measureText(testLine).width > maxWidth && current !== '') {
                lines.push(current);
                current = char;
            } else {
                current = testLine;
            }
        }
        if (current) lines.push(current);
        return lines.slice(0, 4); // max 4 lines
    }
    const lines: string[] = [];
    let currentLine = '';
    for (const word of words) {
        const testLine = currentLine ? `${currentLine} ${word}` : word;
        if (ctx.measureText(testLine).width > maxWidth && currentLine !== '') {
            lines.push(currentLine);
            currentLine = word;
        } else {
            currentLine = testLine;
        }
    }
    if (currentLine) lines.push(currentLine);
    return lines.slice(0, 4);
}

/**
 * Generate a high-resolution, lightweight Thai textbook cover locally on canvas.
 * Produces a ~35 KB JPEG Blob with crisp typography and district branding.
 */
export async function generateSmartCoverBlob(options: SmartCoverOptions): Promise<Blob> {
    if (typeof document === 'undefined') {
        throw new Error('Canvas rendering requires a browser environment');
    }
    const width = 600;
    const height = 848; // A4 aspect ratio (1 : 1.414)
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    if (!ctx) throw new Error('Cannot get 2D context from canvas');

    const theme = levelThemes[options.level] || levelThemes['2'];
    const levelName = options.levelLabel || (options.level === '1' ? 'ระดับประถมศึกษา' : options.level === '3' ? 'ระดับมัธยมศึกษาตอนปลาย' : 'ระดับมัธยมศึกษาตอนต้น');
    const districtText = options.districtName || 'ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอ';

    // 1. Rich Background Gradient
    const gradient = ctx.createLinearGradient(0, 0, width, height);
    gradient.addColorStop(0, theme.bg1);
    gradient.addColorStop(0.45, theme.bg2);
    gradient.addColorStop(1, '#0f172a');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, width, height);

    // 2. Subtle Geometric / Wave Pattern
    ctx.save();
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.05)';
    ctx.lineWidth = 1.5;
    for (let r = 100; r < 900; r += 70) {
        ctx.beginPath();
        ctx.arc(width * 0.85, height * 0.2, r, 0, Math.PI * 2);
        ctx.stroke();
    }
    for (let r = 80; r < 700; r += 60) {
        ctx.beginPath();
        ctx.arc(width * 0.1, height * 0.9, r, 0, Math.PI * 2);
        ctx.stroke();
    }
    ctx.restore();

    // 3. Double Framing (Textbook Gold / White Border)
    ctx.save();
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.35)';
    ctx.lineWidth = 2;
    ctx.strokeRect(26, 26, width - 52, height - 52);

    ctx.strokeStyle = 'rgba(255, 255, 255, 0.15)';
    ctx.lineWidth = 1;
    ctx.strokeRect(32, 32, width - 64, height - 64);

    // Corner flourishes
    const corners = [
        [32, 32], [width - 32, 32], [32, height - 32], [width - 32, height - 32]
    ];
    ctx.fillStyle = theme.accent;
    for (const [cx, cy] of corners) {
        ctx.beginPath();
        ctx.arc(cx, cy, 4, 0, Math.PI * 2);
        ctx.fill();
    }
    ctx.restore();

    // 4. Header: District & Academic Branding
    ctx.save();
    ctx.textAlign = 'center';
    ctx.fillStyle = 'rgba(255, 255, 255, 0.85)';
    ctx.font = '600 17px "Prompt", "Anuphan", sans-serif';
    ctx.fillText('SDL School · การจัดการเรียนรู้', width / 2, 68);

    ctx.fillStyle = '#ffffff';
    ctx.font = '700 20px "Prompt", "Anuphan", sans-serif';
    ctx.fillText(districtText, width / 2, 98);

    ctx.strokeStyle = 'rgba(255, 255, 255, 0.25)';
    ctx.beginPath();
    ctx.moveTo(100, 115);
    ctx.lineTo(width - 100, 115);
    ctx.stroke();
    ctx.restore();

    // 5. Center Card Banner (White / Translucent Card)
    const cardX = 48;
    const cardY = 145;
    const cardW = width - 96;
    const cardH = 430;

    ctx.save();
    ctx.fillStyle = 'rgba(255, 255, 255, 0.96)';
    ctx.shadowColor = 'rgba(0, 0, 0, 0.25)';
    ctx.shadowBlur = 24;
    ctx.shadowOffsetY = 10;
    roundRect(ctx, cardX, cardY, cardW, cardH, 20);
    ctx.fill();
    ctx.shadowColor = 'transparent';

    // Top pill in card: "แผนการจัดการเรียนรู้"
    ctx.fillStyle = theme.bg2;
    roundRect(ctx, cardX + (cardW - 220) / 2, cardY + 28, 220, 36, 18);
    ctx.fill();

    ctx.textAlign = 'center';
    ctx.fillStyle = '#ffffff';
    ctx.font = '700 17px "Prompt", "Anuphan", sans-serif';
    ctx.fillText('แผนการจัดการเรียนรู้', width / 2, cardY + 52);

    // Education Level Ribbon
    ctx.fillStyle = theme.bg1;
    ctx.font = '800 24px "Prompt", "Anuphan", sans-serif';
    ctx.fillText(levelName, width / 2, cardY + 105);

    // Divider line
    ctx.strokeStyle = '#e2e8f0';
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.moveTo(cardX + 40, cardY + 126);
    ctx.lineTo(cardX + cardW - 40, cardY + 126);
    ctx.stroke();

    // Lesson Plan Title
    ctx.fillStyle = '#1e293b';
    ctx.font = '800 28px "Prompt", "Anuphan", sans-serif';
    const titleLines = wrapText(ctx, options.title || 'แผนการสอน', cardW - 60);
    let titleY = cardY + 172;
    for (const line of titleLines) {
        ctx.fillText(line, width / 2, titleY);
        titleY += 38;
    }

    // Scope or Subject Badge
    const scopeOrSubject = options.scopeLabel || (options.subject ? `รายวิชา ${options.subject}` : 'แผนรวมทุกวิชา');
    ctx.fillStyle = '#f1f5f9';
    const badgeW = Math.min(cardW - 60, Math.max(160, ctx.measureText(scopeOrSubject).width + 36));
    roundRect(ctx, cardX + (cardW - badgeW) / 2, cardY + cardH - 120, badgeW, 36, 10);
    ctx.fill();

    ctx.fillStyle = theme.bg2;
    ctx.font = '700 16px "Prompt", "Anuphan", sans-serif';
    ctx.fillText(scopeOrSubject, width / 2, cardY + cardH - 96);

    // Academic Term Pill
    if (options.semester) {
        ctx.fillStyle = '#64748b';
        ctx.font = '600 16px "Prompt", "Anuphan", sans-serif';
        ctx.fillText(`ภาคเรียนที่ ${options.semester}`, width / 2, cardY + cardH - 42);
    }

    ctx.restore();

    // 6. Footer (Teacher & Attribution)
    ctx.save();
    ctx.textAlign = 'center';
    if (options.teacherName) {
        ctx.fillStyle = '#ffffff';
        ctx.font = '700 19px "Prompt", "Anuphan", sans-serif';
        ctx.fillText(`ผู้จัดทำ: ${options.teacherName}`, width / 2, height - 145);
    }

    ctx.fillStyle = theme.accent;
    ctx.font = '600 15px "Prompt", "Anuphan", sans-serif';
    ctx.fillText('ระบบบริหารจัดการการเรียนรู้ SDL School', width / 2, height - 90);
    ctx.restore();

    return new Promise((resolve, reject) => {
        canvas.toBlob(blob => {
            if (blob) resolve(blob);
            else reject(new Error('ไม่สามารถสร้างภาพหน้าปกได้'));
        }, 'image/jpeg', 0.90);
    });
}

function roundRect(ctx: CanvasRenderingContext2D, x: number, y: number, w: number, h: number, r: number) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
}
