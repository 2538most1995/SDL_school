<?php

return [
    'demo_mode' => env('SENA_DEMO_MODE', false),
    'modules' => [
        [
            'key' => 'overview',
            'label' => 'ภาพรวม',
            'color' => 'blue',
            'items' => [
                ['key' => 'dashboard', 'label' => 'หน้าแรก', 'description' => 'สิ่งที่ต้องทำและภาพรวมการเรียน', 'route' => '/app', 'icon' => 'house', 'roles' => ['student', 'teacher', 'admin', 'super_admin']],
            ],
        ],
        [
            'key' => 'basic-information',
            'label' => 'ข้อมูลพื้นฐาน',
            'color' => 'blue',
            'items' => [
                ['key' => 'students', 'label' => 'ข้อมูลนักศึกษา', 'description' => 'ค้นหา กรอง และดูข้อมูลนักศึกษา', 'route' => '/students', 'icon' => 'students', 'roles' => ['teacher', 'admin', 'super_admin']],
                ['key' => 'my-learning', 'label' => 'ข้อมูลการเรียนของฉัน', 'description' => 'ข้อมูลประจำตัวและสถานะการศึกษา', 'route' => '/my-learning', 'icon' => 'student', 'roles' => ['student']],
                ['key' => 'grades', 'label' => 'ผลการเรียน', 'description' => 'รายวิชา เกรด GPAX และหน่วยกิต', 'route' => '/grades', 'icon' => 'chart', 'roles' => ['student', 'teacher', 'admin', 'super_admin']],
                ['key' => 'kpch', 'label' => 'กิจกรรม กพช.', 'description' => 'ชั่วโมงกิจกรรมและเกณฑ์ผ่าน', 'route' => '/kpch', 'icon' => 'sparkle', 'roles' => ['student', 'teacher', 'admin', 'super_admin']],
                ['key' => 'moral', 'label' => 'คุณธรรม', 'description' => 'ผลประเมินคุณธรรมรายภาคเรียน', 'route' => '/moral', 'icon' => 'heart', 'roles' => ['student', 'teacher', 'admin', 'super_admin']],
            ],
        ],
        [
            'key' => 'reports',
            'label' => 'รายงาน',
            'color' => 'coral',
            'items' => [
                ['key' => 'new-students', 'label' => 'นักศึกษาใหม่', 'description' => 'นักศึกษาใหม่ภาคเรียนปัจจุบัน', 'route' => '/reports/new-students', 'icon' => 'user-plus', 'roles' => ['teacher', 'admin', 'super_admin']],
                ['key' => 'graduates', 'label' => 'ผู้จบหลักสูตร', 'description' => 'สถานะจบและภาคเรียนที่จบ', 'route' => '/reports/graduates', 'icon' => 'medal', 'roles' => ['teacher', 'admin', 'super_admin']],
                ['key' => 'expected-graduates', 'label' => 'นักศึกษาคาดว่าจะจบ', 'description' => 'นักศึกษาผ่านเกณฑ์หน่วยกิตรวมภาคเรียนปัจจุบัน', 'route' => '/reports/expected-graduates', 'icon' => 'certificate', 'roles' => ['teacher', 'admin', 'super_admin']],
                ['key' => 'transfers', 'label' => 'ข้อมูลเทียบโอน', 'description' => 'รายวิชาและผลการเทียบโอน', 'route' => '/reports/transfers', 'icon' => 'arrows', 'roles' => ['teacher', 'admin', 'super_admin']],
                ['key' => 'subjects', 'label' => 'วิชาลงทะเบียน', 'description' => 'รายวิชาที่ลงทะเบียนตามภาคเรียน', 'route' => '/reports/registered-subjects', 'icon' => 'books', 'roles' => ['teacher', 'admin', 'super_admin']],
                ['key' => 'grade-threshold', 'label' => 'สถิติเกรด 2 ขึ้นไป', 'description' => 'สรุปผลสัมฤทธิ์ตามระดับและกลุ่ม', 'route' => '/reports/grade-threshold', 'icon' => 'trend', 'roles' => ['teacher', 'admin', 'super_admin']],
                ['key' => 'exam-attendance', 'label' => 'สถิติการเข้าสอบ', 'description' => 'การเข้าสอบตามวิชาและห้องสอบ', 'route' => '/reports/exam-attendance', 'icon' => 'clipboard', 'roles' => ['teacher', 'admin', 'super_admin']],
            ],
        ],
        [
            'key' => 'learning',
            'label' => 'learning',
            'color' => 'violet',
            'items' => [
                ['key' => 'learning-home', 'label' => 'พื้นที่การเรียนรู้', 'description' => 'ภาพรวมการเรียนและการสอน', 'route' => '/learning', 'icon' => 'planet', 'roles' => ['student', 'teacher', 'admin', 'super_admin']],
                ['key' => 'assignments', 'label' => 'งานและการส่งงาน', 'description' => 'มอบหมาย ส่ง ตรวจ และให้คะแนนงาน', 'route' => '/learning/assignments', 'icon' => 'assignment', 'roles' => ['student', 'teacher', 'admin', 'super_admin']],
                ['key' => 'resources', 'label' => 'คลังสื่อ', 'description' => 'เอกสารและสื่อประกอบการเรียน', 'route' => '/learning/resources', 'icon' => 'folder', 'roles' => ['student', 'teacher', 'admin', 'super_admin']],
                ['key' => 'lesson-plans', 'label' => 'แผนการสอน', 'description' => 'แผนการจัดการเรียนรู้ของครู', 'route' => '/learning/lesson-plans', 'icon' => 'notebook', 'roles' => ['teacher', 'admin', 'super_admin']],
                ['key' => 'calendar', 'label' => 'ปฏิทินพบกลุ่ม', 'description' => 'กิจกรรม วันพบกลุ่ม และกำหนดส่ง', 'route' => '/learning/calendar', 'icon' => 'calendar', 'roles' => ['student', 'teacher', 'admin', 'super_admin']],
                ['key' => 'schedule', 'label' => 'ตารางสอบ', 'description' => 'ตารางสอบรายนักศึกษาและสร้าง PDF', 'route' => '/learning/schedule', 'icon' => 'clock', 'roles' => ['student', 'teacher', 'admin', 'super_admin']],
                ['key' => 'scores', 'label' => 'คะแนนเก็บ', 'description' => 'คะแนนระหว่างภาคและผลการประเมิน', 'route' => '/learning/scores', 'icon' => 'trophy', 'roles' => ['student', 'teacher', 'admin', 'super_admin']],
            ],
        ],
        [
            'key' => 'administration',
            'label' => 'จัดการระบบ',
            'color' => 'amber',
            'items' => [
                ['key' => 'districts', 'label' => 'ทะเบียนอำเภอ', 'description' => 'เพิ่มพื้นที่ใหม่ก่อนสร้างผู้ดูแลและนำเข้าข้อมูล', 'route' => '/super-admin/districts', 'icon' => 'buildings', 'roles' => ['super_admin']],
                ['key' => 'users', 'label' => 'ผู้ใช้งาน', 'description' => 'สิทธิ์ ครู ผู้ดูแล และขอบเขตกลุ่ม', 'route' => '/admin/users', 'icon' => 'users-three', 'roles' => ['admin', 'super_admin']],
                ['key' => 'exam-rooms', 'label' => 'ห้องสอบ', 'description' => 'จัดห้องสอบและตรวจสอบรายชื่อ', 'route' => '/admin/exam-rooms', 'icon' => 'door', 'roles' => ['admin', 'super_admin']],
                ['key' => 'imports', 'label' => 'นำเข้าข้อมูล', 'description' => 'ZIP, DBF, batch และผล validation', 'route' => '/admin/imports', 'icon' => 'upload', 'roles' => ['admin', 'super_admin']],
                ['key' => 'maintenance', 'label' => 'ดูแลข้อมูล', 'description' => 'ประวัติ batch และพื้นที่อันตราย', 'route' => '/admin/data-maintenance', 'icon' => 'database', 'roles' => ['admin', 'super_admin']],
                ['key' => 'branding', 'label' => 'แบรนด์และสื่อของระบบ', 'description' => 'ชื่อ สี โลโก้ และภาพหลักของพื้นที่', 'route' => '/admin/branding', 'icon' => 'palette', 'roles' => ['admin', 'super_admin']],
            ],
        ],
        [
            'key' => 'settings',
            'label' => 'การตั้งค่า',
            'color' => 'slate',
            'items' => [
                ['key' => 'profile', 'label' => 'โปรไฟล์', 'description' => 'ข้อมูลส่วนตัวและความปลอดภัย', 'route' => '/settings/profile', 'icon' => 'user', 'roles' => ['student', 'teacher', 'admin', 'super_admin']],
                ['key' => 'appearance', 'label' => 'รูปแบบการแสดงผล', 'description' => 'โหมด สี ขนาดตัวอักษร และความหนาแน่น', 'route' => '/settings/appearance', 'icon' => 'paint-brush', 'roles' => ['student', 'teacher', 'admin', 'super_admin']],
            ],
        ],
    ],
];
