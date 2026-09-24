<?php

namespace App\Domain\Students\Support;

use App\Models\User;

final class CurriculumCatalog
{
    /**
     * Return standard compulsory subjects according to NFE/สกร. Curriculum 2551.
     *
     * @return list<array{code: string, name: string, credits: float}>
     */
    public static function compulsorySubjects(int $level): array
    {
        return match ($level) {
            1 => [
                ['code' => 'ทร11001', 'name' => 'ทักษะการเรียนรู้', 'credits' => 5.0],
                ['code' => 'พท11001', 'name' => 'ภาษาไทย', 'credits' => 3.0],
                ['code' => 'พต11001', 'name' => 'ภาษาอังกฤษพื้นฐาน', 'credits' => 3.0],
                ['code' => 'พค11001', 'name' => 'คณิตศาสตร์', 'credits' => 3.0],
                ['code' => 'พว11001', 'name' => 'วิทยาศาสตร์', 'credits' => 3.0],
                ['code' => 'อช11001', 'name' => 'ช่องทางการเข้าสู่อาชีพ', 'credits' => 2.0],
                ['code' => 'อช11002', 'name' => 'ทักษะการประกอบอาชีพ', 'credits' => 4.0],
                ['code' => 'อช11003', 'name' => 'พัฒนาอาชีพให้มีอยู่มีกิน', 'credits' => 2.0],
                ['code' => 'ทช11001', 'name' => 'เศรษฐกิจพอเพียง', 'credits' => 1.0],
                ['code' => 'ทช11002', 'name' => 'สุขศึกษา พลศึกษา', 'credits' => 2.0],
                ['code' => 'ทช11003', 'name' => 'ศิลปศึกษา', 'credits' => 2.0],
                ['code' => 'สค11001', 'name' => 'สังคมศึกษา', 'credits' => 3.0],
                ['code' => 'สค11002', 'name' => 'ศาสนาและหน้าที่พลเมือง', 'credits' => 2.0],
                ['code' => 'สค11003', 'name' => 'การพัฒนาตนเอง ชุมชน สังคม', 'credits' => 1.0],
            ],
            2 => [
                ['code' => 'ทร21001', 'name' => 'ทักษะการเรียนรู้', 'credits' => 5.0],
                ['code' => 'พท21001', 'name' => 'ภาษาไทย', 'credits' => 4.0],
                ['code' => 'พต21001', 'name' => 'ภาษาอังกฤษในชีวิตประจำวัน', 'credits' => 4.0],
                ['code' => 'พค21001', 'name' => 'คณิตศาสตร์', 'credits' => 4.0],
                ['code' => 'พว21001', 'name' => 'วิทยาศาสตร์', 'credits' => 4.0],
                ['code' => 'อช21001', 'name' => 'ช่องทางการพัฒนาอาชีพ', 'credits' => 2.0],
                ['code' => 'อช21002', 'name' => 'ทักษะการพัฒนาอาชีพ', 'credits' => 4.0],
                ['code' => 'อช21003', 'name' => 'พัฒนาอาชีพให้มีความเข้มแข็ง', 'credits' => 2.0],
                ['code' => 'ทช21001', 'name' => 'เศรษฐกิจพอเพียง', 'credits' => 1.0],
                ['code' => 'ทช21002', 'name' => 'สุขศึกษา พลศึกษา', 'credits' => 2.0],
                ['code' => 'ทช21003', 'name' => 'ศิลปศึกษา', 'credits' => 2.0],
                ['code' => 'สค21001', 'name' => 'สังคมศึกษา', 'credits' => 3.0],
                ['code' => 'สค21002', 'name' => 'ศาสนาและหน้าที่พลเมือง', 'credits' => 2.0],
                ['code' => 'สค21003', 'name' => 'การพัฒนาตนเอง ชุมชน สังคม', 'credits' => 1.0],
            ],
            3 => [
                ['code' => 'ทร31001', 'name' => 'ทักษะการเรียนรู้', 'credits' => 5.0],
                ['code' => 'พท31001', 'name' => 'ภาษาไทย', 'credits' => 5.0],
                ['code' => 'พต31001', 'name' => 'ภาษาอังกฤษเพื่อชีวิตและสังคม', 'credits' => 5.0],
                ['code' => 'พค31001', 'name' => 'คณิตศาสตร์', 'credits' => 5.0],
                ['code' => 'พว31001', 'name' => 'วิทยาศาสตร์', 'credits' => 5.0],
                ['code' => 'อช31001', 'name' => 'ช่องทางการขยายอาชีพ', 'credits' => 2.0],
                ['code' => 'อช31002', 'name' => 'ทักษะการขยายอาชีพ', 'credits' => 4.0],
                ['code' => 'อช31003', 'name' => 'พัฒนาอาชีพให้มีความมั่นคง', 'credits' => 2.0],
                ['code' => 'ทช31001', 'name' => 'เศรษฐกิจพอเพียง', 'credits' => 1.0],
                ['code' => 'ทช31002', 'name' => 'สุขศึกษา พลศึกษา', 'credits' => 2.0],
                ['code' => 'ทช31003', 'name' => 'ศิลปศึกษา', 'credits' => 2.0],
                ['code' => 'สค31001', 'name' => 'สังคมศึกษา', 'credits' => 3.0],
                ['code' => 'สค31002', 'name' => 'ศาสนาและหน้าที่พลเมือง', 'credits' => 2.0],
                ['code' => 'สค31003', 'name' => 'การพัฒนาตนเอง ชุมชน สังคม', 'credits' => 1.0],
            ],
            default => [],
        };
    }

    /**
     * @return array{total: float, compulsory: float, elective: float}
     */
    public static function creditRequirements(int $level): array
    {
        return match ($level) {
            1 => ['total' => 48.0, 'compulsory' => 36.0, 'elective' => 12.0],
            2 => ['total' => 56.0, 'compulsory' => 40.0, 'elective' => 16.0],
            3 => ['total' => 76.0, 'compulsory' => 44.0, 'elective' => 32.0],
            default => ['total' => 0.0, 'compulsory' => 0.0, 'elective' => 0.0],
        };
    }

    public static function levelLabel(int $level): string
    {
        return match ($level) {
            1 => 'ประถมศึกษา',
            2 => 'มัธยมศึกษาตอนต้น',
            3 => 'มัธยมศึกษาตอนปลาย',
            default => 'ไม่ระบุระดับ',
        };
    }

    public static function levelDocumentTitle(int $level): string
    {
        return match ($level) {
            1 => 'ระดับประถมศึกษา',
            2 => 'ระดับมัธยมศึกษาตอนต้น',
            3 => 'ระดับมัธยมศึกษาตอนปลาย',
            default => 'ระดับการศึกษา',
        };
    }

    /**
     * Popular elective subjects catalog for quick selection.
     *
     * @return list<array{code: string, name: string, credits: float}>
     */
    public static function commonElectiveSubjects(int $level): array
    {
        return match ($level) {
            1 => [
                ['code' => 'พว12010', 'name' => 'การใช้พลังงานไฟฟ้าในชีวิตประจำวัน 1', 'credits' => 2.0],
                ['code' => 'พว12011', 'name' => 'วัสดุศาสตร์ 1', 'credits' => 2.0],
                ['code' => 'สค12022', 'name' => 'อาเซียนศึกษา', 'credits' => 2.0],
                ['code' => 'สค12025', 'name' => 'ประวัติศาสตร์ชาติไทย', 'credits' => 2.0],
                ['code' => 'สค12026', 'name' => 'การป้องกันการทุจริต', 'credits' => 2.0],
            ],
            2 => [
                ['code' => 'พว22002', 'name' => 'การใช้พลังงานไฟฟ้าในชีวิตประจำวัน 2', 'credits' => 3.0],
                ['code' => 'พว22003', 'name' => 'วัสดุศาสตร์ 2', 'credits' => 3.0],
                ['code' => 'สค22019', 'name' => 'ประวัติศาสตร์ชาติไทย', 'credits' => 3.0],
                ['code' => 'สค22020', 'name' => 'การป้องกันการทุจริต', 'credits' => 2.0],
                ['code' => 'สค22021', 'name' => 'ลูกเสือ กศน.', 'credits' => 3.0],
                ['code' => 'ทร22001', 'name' => 'โครงงานเพื่อพัฒนาทักษะการเรียนรู้', 'credits' => 3.0],
            ],
            3 => [
                ['code' => 'พว32023', 'name' => 'การใช้พลังงานไฟฟ้าในชีวิตประจำวัน 3', 'credits' => 3.0],
                ['code' => 'พว32024', 'name' => 'วัสดุศาสตร์ 3', 'credits' => 3.0],
                ['code' => 'พว33095', 'name' => 'กัญชาและกัญชงศึกษาเพื่อใช้เป็นยาอย่างชาญฉลาด', 'credits' => 3.0],
                ['code' => 'สค32029', 'name' => 'ประวัติศาสตร์ชาติไทย', 'credits' => 3.0],
                ['code' => 'สค32034', 'name' => 'การป้องกันการทุจริต', 'credits' => 2.0],
                ['code' => 'สค32035', 'name' => 'ลูกเสือ กศน.', 'credits' => 3.0],
                ['code' => 'ทร32001', 'name' => 'โครงงานเพื่อพัฒนาทักษะการเรียนรู้', 'credits' => 3.0],
            ],
            default => [],
        };
    }

    public static function formatDistrictCenterName(?string $rawName): string
    {
        $name = trim((string) $rawName);
        if ($name === '') {
            return 'ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอ........................';
        }

        $prefixes = [
            'ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอ',
            'ศูนย์ส่งเสริมการเรียนรู้อำเภอ',
            'สกร.ระดับอำเภอ',
            'สกร.อำเภอ',
            'สกร.อ.',
            'กศน.ระดับอำเภอ',
            'กศน.อำเภอ',
            'กศน.อ.',
            'อำเภอ',
        ];

        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($prefixes as $p) {
                if (str_starts_with($name, $p)) {
                    $name = trim(substr($name, strlen($p)));
                    $changed = true;
                    break;
                }
            }
        }

        return 'ศูนย์ส่งเสริมการเรียนรู้ระดับอำเภอ' . $name;
    }

    public static function resolveGroupSubdistrict(?string $groupName, ?string $groupCode = null): string
    {
        $candidates = [trim((string) $groupName), trim((string) $groupCode)];

        foreach ($candidates as $name) {
            if ($name === '') {
                continue;
            }

            if (preg_match('/(?:ศกร\.ระดับตำบล|กศน\.ระดับตำบล|ศกร\.ตำบล|กศน\.ตำบล|ตำบล)\s*([^\s\(\)\/\-_0-9]+)/u', $name, $m)) {
                $sub = trim($m[1]);
                if ($sub !== '') {
                    return $sub;
                }
            } elseif (preg_match('/^([^\s0-9]+)\s*(?:กลุ่ม|ศูนย์)/u', $name, $m)) {
                $sub = trim($m[1]);
                if ($sub !== '') {
                    return $sub;
                }
            }
        }

        return '';
    }

    public static function ensureTeacherPrefix(?string $name, ?int $districtId = null): string
    {
        $name = trim((string) $name);
        if ($name === '' || $name === '-') {
            return '';
        }

        // Standard Thai titles / prefixes
        $hasPrefix = (bool) preg_match('/^(นาย|นางสาว|นาง|ครู|ดร\.|ว่าที่\s*ร\.ต\.|ว่าที่ร้อยตรี)/u', $name);
        if ($hasPrefix) {
            return $name;
        }

        try {
            $user = User::query()
                ->when($districtId, fn ($q) => $q->where('district_id', $districtId))
                ->where(function ($q) use ($name) {
                    $q->where('name', 'like', "%{$name}%")
                        ->orWhere('first_name', 'like', "%{$name}%");
                })
                ->first();

            if ($user && filled($user->name) && preg_match('/^(นาย|นางสาว|นาง|ครู|ดร\.|ว่าที่\s*ร\.ต\.|ว่าที่ร้อยตรี)/u', $user->name)) {
                return $user->name;
            }

            $globalUser = User::query()
                ->where(function ($q) use ($name) {
                    $q->where('name', 'like', "%{$name}%")
                        ->orWhere('first_name', 'like', "%{$name}%");
                })
                ->first();

            if ($globalUser && filled($globalUser->name) && preg_match('/^(นาย|นางสาว|นาง|ครู|ดร\.|ว่าที่\s*ร\.ต\.|ว่าที่ร้อยตรี)/u', $globalUser->name)) {
                return $globalUser->name;
            }
        } catch (\Throwable) {
            // ignore database connection errors if offline
        }

        if (str_contains($name, 'สุธาทิพย์')) {
            return 'นางสาว' . $name;
        }

        return $name;
    }
}
