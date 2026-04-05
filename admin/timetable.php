<?php
include '../config.php';
checkAuth();
checkRole(['admin']);

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$userId = (int) ($_SESSION['user_id'] ?? 0);

function timetableRedirect(string $messageKey, string $message, ?int $planId = null): void
{
    $_SESSION[$messageKey] = $message;
    $location = 'timetable.php';
    if ($planId) {
        $location .= '?plan_id=' . $planId;
    }
    header('Location: ' . $location);
    exit();
}

function timetableTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function timetableColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

function ensureTimetableSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS timetable_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            academic_year VARCHAR(20) NOT NULL,
            term VARCHAR(50) NOT NULL,
            effective_from DATE DEFAULT NULL,
            status ENUM('draft','in_review','published','archived') NOT NULL DEFAULT 'draft',
            review_notes TEXT DEFAULT NULL,
            created_by INT DEFAULT NULL,
            reviewed_by INT DEFAULT NULL,
            published_by INT DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            published_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS timetable_periods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plan_id INT NOT NULL,
            label VARCHAR(100) NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            period_type ENUM('lesson','break','lunch','games') NOT NULL DEFAULT 'lesson',
            sort_order INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_timetable_periods_plan (plan_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS timetable_class_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            is_available TINYINT(1) NOT NULL DEFAULT 1,
            notes VARCHAR(255) DEFAULT NULL,
            updated_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_class_setting (class_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS timetable_teacher_availability (
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL,
            day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday') NOT NULL,
            period_id INT NOT NULL,
            is_available TINYINT(1) NOT NULL DEFAULT 1,
            notes VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_teacher_slot (teacher_id, day_of_week, period_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!timetableColumnExists($pdo, 'timetable_lessons', 'plan_id')) {
        $pdo->exec("ALTER TABLE timetable_lessons ADD COLUMN plan_id INT NULL AFTER id");
    }
    if (!timetableColumnExists($pdo, 'timetable_lessons', 'period_id')) {
        $pdo->exec("ALTER TABLE timetable_lessons ADD COLUMN period_id INT NULL AFTER plan_id");
    }
    if (!timetableColumnExists($pdo, 'timetable_lessons', 'status')) {
        $pdo->exec("ALTER TABLE timetable_lessons ADD COLUMN status ENUM('draft','in_review','published','archived') NOT NULL DEFAULT 'draft' AFTER academic_year");
    }
    if (!timetableColumnExists($pdo, 'timetable_lessons', 'notes')) {
        $pdo->exec("ALTER TABLE timetable_lessons ADD COLUMN notes VARCHAR(255) NULL AFTER status");
    }
    if (!timetableColumnExists($pdo, 'subjects', 'weekly_lessons_per_week')) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN weekly_lessons_per_week INT NOT NULL DEFAULT 4 AFTER teacher_id");
    }
    if (!timetableColumnExists($pdo, 'subjects', 'max_lessons_per_day')) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN max_lessons_per_day INT NOT NULL DEFAULT 1 AFTER weekly_lessons_per_week");
    }
    if (!timetableColumnExists($pdo, 'subjects', 'allow_double_period')) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN allow_double_period TINYINT(1) NOT NULL DEFAULT 0 AFTER max_lessons_per_day");
    }

    try {
        $pdo->exec("
            ALTER TABLE rooms
            MODIFY COLUMN room_type ENUM('classroom','laboratory','computer_lab','library','office','hall') NOT NULL DEFAULT 'classroom'
        ");
    } catch (Exception $e) {
        // Keep page working even if enum update is blocked.
    }
}

function buildLessonCellMap(array $lessons): array
{
    $map = [];
    foreach ($lessons as $lesson) {
        $map[$lesson['class_id']][$lesson['day_of_week']][$lesson['period_id']] = $lesson;
        $map['teacher'][$lesson['teacher_id']][$lesson['day_of_week']][$lesson['period_id']] = $lesson;
    }
    return $map;
}

function normalizeSubjectName(string $subjectName): string
{
    return strtolower(trim($subjectName));
}

function isComputerSubject(string $subjectName): bool
{
    $name = normalizeSubjectName($subjectName);
    return str_contains($name, 'computer') || str_contains($name, 'ict') || str_contains($name, 'coding');
}

function isScienceSubject(string $subjectName): bool
{
    $name = normalizeSubjectName($subjectName);
    return
        str_contains($name, 'chem') ||
        str_contains($name, 'phys') ||
        str_contains($name, 'bio') ||
        str_contains($name, 'science');
}

function isPhysicalEducationSubject(string $subjectName): bool
{
    $name = normalizeSubjectName($subjectName);
    return
        preg_match('/\bpe\b/', $name) === 1 ||
        str_contains($name, 'physical education') ||
        str_contains($name, 'games') ||
        str_contains($name, 'sport');
}

function isLibrarySubject(string $subjectName): bool
{
    $name = normalizeSubjectName($subjectName);
    return str_contains($name, 'library') || str_contains($name, 'reading');
}

function inferPreferredRoomTypes(string $subjectName, bool $isDoubleLesson = false): array
{
    if (isComputerSubject($subjectName)) {
        return $isDoubleLesson ? ['computer_lab', 'classroom'] : ['classroom'];
    }
    if (isScienceSubject($subjectName)) {
        return $isDoubleLesson ? ['laboratory', 'classroom'] : ['classroom'];
    }
    if (isLibrarySubject($subjectName)) {
        return ['library', 'classroom'];
    }
    if (isPhysicalEducationSubject($subjectName)) {
        return ['hall', 'classroom'];
    }
    $name = normalizeSubjectName($subjectName);
    if (str_contains($name, 'music') || str_contains($name, 'drama') || str_contains($name, 'assembly')) {
        return ['hall', 'classroom'];
    }

    return ['classroom', 'hall', 'library', 'office'];
}

function subjectNeedsWeeklyPeSlot(array $subject): bool
{
    return isPhysicalEducationSubject((string) ($subject['subject_name'] ?? ''));
}

function effectiveWeeklyLessonTarget(array $subject): int
{
    $target = max(0, (int) ($subject['weekly_lessons_per_week'] ?? 0));
    if ($target === 0 && subjectNeedsWeeklyPeSlot($subject)) {
        return 1;
    }
    return $target;
}

function chooseRoomForSubject(
    array $subject,
    array $roomsByType,
    array $roomBusy,
    string $day,
    array $periodIds,
    bool $isDoubleLesson = false
): ?int
{
    $periodIds = array_values(array_unique(array_map('intval', $periodIds)));
    if (!$isDoubleLesson) {
        return null;
    }

    $preferredTypes = inferPreferredRoomTypes((string) ($subject['subject_name'] ?? ''), $isDoubleLesson);

    foreach ($preferredTypes as $roomType) {
        foreach ($roomsByType[$roomType] ?? [] as $room) {
            $roomId = (int) $room['id'];
            $isFree = true;
            foreach ($periodIds as $periodId) {
                if (isset($roomBusy[$roomId][$day][$periodId])) {
                    $isFree = false;
                    break;
                }
            }
            if ($isFree) {
                return $roomId;
            }
        }
    }

    foreach ($roomsByType as $roomType => $rooms) {
        if (in_array($roomType, ['laboratory', 'computer_lab'], true) && !in_array($roomType, $preferredTypes, true)) {
            continue;
        }
        foreach ($rooms as $room) {
            $roomId = (int) $room['id'];
            $isFree = true;
            foreach ($periodIds as $periodId) {
                if (isset($roomBusy[$roomId][$day][$periodId])) {
                    $isFree = false;
                    break;
                }
            }
            if ($isFree) {
                return $roomId;
            }
        }
    }

    return null;
}

function getNeighborLessonPeriodIds(array $lessonPeriods, int $periodId): array
{
    $periodIds = array_map(fn($period) => (int) $period['id'], $lessonPeriods);
    $currentIndex = array_search($periodId, $periodIds, true);
    if ($currentIndex === false) {
        return [];
    }

    $neighbors = [];
    if (isset($lessonPeriods[$currentIndex - 1])) {
        $neighbors[] = (int) $lessonPeriods[$currentIndex - 1]['id'];
    }
    if (isset($lessonPeriods[$currentIndex + 1])) {
        $neighbors[] = (int) $lessonPeriods[$currentIndex + 1]['id'];
    }

    return shufflePreserve($neighbors);
}

function roomTypeIsAllowedForSubject(string $subjectName, ?string $roomType, bool $hasDoubleLesson): bool
{
    if ($roomType === null || $roomType === '') {
        return true;
    }

    if ($roomType === 'laboratory') {
        return isScienceSubject($subjectName) && $hasDoubleLesson;
    }
    if ($roomType === 'computer_lab') {
        return isComputerSubject($subjectName) && $hasDoubleLesson;
    }

    return true;
}

function isTeacherAvailableForSlot(array $teacherAvailability, int $teacherId, string $day, int $periodId): bool
{
    if (!isset($teacherAvailability[$teacherId][$day][$periodId])) {
        return true;
    }

    return (int) $teacherAvailability[$teacherId][$day][$periodId]['is_available'] === 1;
}

function shufflePreserve(array $items): array
{
    $copy = array_values($items);
    if (count($copy) > 1) {
        shuffle($copy);
    }
    return $copy;
}

function buildRandomDailyLessonTargets(int $totalLessons, int $dayCount, int $periodsPerDay): array
{
    if ($dayCount <= 0) {
        return [];
    }

    $targets = array_fill(0, $dayCount, 0);
    $remaining = max(0, $totalLessons);

    // First spread lessons so no single day carries everything when avoidable.
    while ($remaining > 0) {
        $eligible = [];
        foreach ($targets as $dayIndex => $value) {
            if ($value < $periodsPerDay) {
                $eligible[] = $dayIndex;
            }
        }

        if (empty($eligible)) {
            break;
        }

        shuffle($eligible);
        $picked = false;
        foreach ($eligible as $dayIndex) {
            $currentMax = max($targets);
            if ($targets[$dayIndex] <= $currentMax - 1 || $currentMax === 0) {
                $targets[$dayIndex]++;
                $remaining--;
                $picked = true;
                break;
            }
        }

        if (!$picked) {
            $targets[$eligible[0]]++;
            $remaining--;
        }
    }

    return $targets;
}

function pickSpreadPeriodIds(array $lessonPeriods, int $targetCount): array
{
    $periodCount = count($lessonPeriods);
    if ($targetCount <= 0 || $periodCount === 0) {
        return [];
    }

    $targetCount = min($targetCount, $periodCount);
    $indices = range(0, $periodCount - 1);
    shuffle($indices);

    $selected = [];
    foreach ($indices as $index) {
        $adjacent = false;
        foreach ($selected as $pickedIndex) {
            if (abs($pickedIndex - $index) <= 1) {
                $adjacent = true;
                break;
            }
        }
        if (!$adjacent) {
            $selected[] = $index;
        }
        if (count($selected) >= $targetCount) {
            break;
        }
    }

    if (count($selected) < $targetCount) {
        foreach ($indices as $index) {
            if (!in_array($index, $selected, true)) {
                $selected[] = $index;
            }
            if (count($selected) >= $targetCount) {
                break;
            }
        }
    }

    sort($selected);
    return array_map(fn($index) => (int) $lessonPeriods[$index]['id'], $selected);
}

function generateTimetableDraft(PDO $pdo, int $planId, array $days, int $userId): array
{
    $planStmt = $pdo->prepare("SELECT * FROM timetable_plans WHERE id = ? LIMIT 1");
    $planStmt->execute([$planId]);
    $plan = $planStmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        throw new RuntimeException('Timetable plan not found.');
    }
    if ($plan['status'] === 'published') {
        throw new RuntimeException('Published timetables cannot be auto-regenerated. Create a new plan or archive this one first.');
    }

    $periodStmt = $pdo->prepare("
        SELECT *
        FROM timetable_periods
        WHERE plan_id = ? AND period_type = 'lesson'
        ORDER BY sort_order, start_time
    ");
    $periodStmt->execute([$planId]);
    $lessonPeriods = $periodStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($lessonPeriods)) {
        throw new RuntimeException('Add lesson periods before generating the timetable.');
    }

    $classStmt = $pdo->query("
        SELECT c.id, c.class_name
        FROM classes c
        LEFT JOIN timetable_class_settings tcs ON tcs.class_id = c.id
        WHERE COALESCE(c.is_active, 1) = 1
          AND COALESCE(tcs.is_available, 1) = 1
        ORDER BY c.class_name
    ");
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($classes)) {
        throw new RuntimeException('Mark at least one class as available before generating the timetable.');
    }

    $subjectStmt = $pdo->query("
        SELECT s.id, s.class_id, s.subject_name, s.teacher_id,
               COALESCE(s.weekly_lessons_per_week, 4) AS weekly_lessons_per_week,
               COALESCE(s.max_lessons_per_day, 1) AS max_lessons_per_day,
               COALESCE(s.allow_double_period, 0) AS allow_double_period
        FROM subjects s
        JOIN classes c ON c.id = s.class_id
        LEFT JOIN timetable_class_settings tcs ON tcs.class_id = c.id
        WHERE COALESCE(c.is_active, 1) = 1
          AND COALESCE(tcs.is_available, 1) = 1
        ORDER BY s.class_id, s.subject_name
    ");

    $subjectsByClass = [];
    $classesWithoutTeacher = [];
    foreach ($subjectStmt->fetchAll(PDO::FETCH_ASSOC) as $subject) {
        if (empty($subject['teacher_id'])) {
            $classesWithoutTeacher[$subject['class_id']] = true;
            continue;
        }
        $subjectsByClass[$subject['class_id']][] = $subject;
    }

    $roomsStmt = $pdo->query("
        SELECT id, room_name, room_type
        FROM rooms
        ORDER BY FIELD(room_type, 'laboratory', 'computer_lab', 'library', 'hall', 'classroom', 'office'), room_name
    ");
    $roomsByType = [];
    foreach ($roomsStmt->fetchAll(PDO::FETCH_ASSOC) as $room) {
        $roomsByType[$room['room_type']][] = $room;
    }

    $teacherAvailabilityStmt = $pdo->query("
        SELECT teacher_id, day_of_week, period_id, is_available, notes
        FROM timetable_teacher_availability
    ");
    $teacherAvailability = [];
    foreach ($teacherAvailabilityStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $teacherAvailability[$row['teacher_id']][$row['day_of_week']][$row['period_id']] = $row;
    }

    $activeClasses = [];
    foreach ($classes as $class) {
        if (!empty($subjectsByClass[$class['id']])) {
            $activeClasses[] = $class;
        }
    }
    if (empty($activeClasses)) {
        throw new RuntimeException('None of the available classes have subjects with assigned teachers.');
    }

    $pdo->prepare("DELETE FROM timetable_lessons WHERE plan_id = ?")->execute([$planId]);

    $insertStmt = $pdo->prepare("
        INSERT INTO timetable_lessons (
            plan_id, class_id, subject_id, teacher_id, day_of_week, start_time, end_time,
            room_id, academic_year, period_id, status, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $teacherBusy = [];
    $classBusy = [];
    $roomBusy = [];
    $subjectUsageByClass = [];
    $subjectDayUsageByClass = [];
    $generated = 0;
    $skipped = 0;
    $unmetTargets = 0;
    $periodMap = [];
    foreach ($lessonPeriods as $period) {
        $periodMap[(int) $period['id']] = $period;
    }

    $classSlotPlan = [];
    $periodsPerDay = count($lessonPeriods);
    foreach (shufflePreserve($activeClasses) as $class) {
        $classId = (int) $class['id'];
        $subjectList = $subjectsByClass[$classId] ?? [];
        $peSubjects = array_values(array_filter($subjectList, fn($subject) => subjectNeedsWeeklyPeSlot($subject)));
        $totalRequired = 0;
        foreach ($subjectList as $subject) {
            $totalRequired += effectiveWeeklyLessonTarget($subject);
        }

        $dailyTargets = buildRandomDailyLessonTargets($totalRequired, count($days), $periodsPerDay);

        if (!empty($peSubjects)) {
            $bestDayIndex = 0;
            $bestTarget = null;
            foreach ($dailyTargets as $dayIndex => $target) {
                if ($bestTarget === null || $target < $bestTarget) {
                    $bestTarget = $target;
                    $bestDayIndex = $dayIndex;
                }
            }
            $dailyTargets[$bestDayIndex] = min($periodsPerDay, ($dailyTargets[$bestDayIndex] ?? 0) + 1);
        }

        foreach ($days as $dayIndex => $day) {
            $pickedPeriodIds = pickSpreadPeriodIds($lessonPeriods, $dailyTargets[$dayIndex] ?? 0);
            foreach ($pickedPeriodIds as $pickedPeriodId) {
                $preferredSubjectIds = [];
                if (!empty($peSubjects)) {
                    $preferredSubjectIds[] = (int) $peSubjects[0]['id'];
                }
                $classSlotPlan[] = [
                    'class_id' => $classId,
                    'day' => $day,
                    'period_id' => $pickedPeriodId,
                    'preferred_subject_ids' => $preferredSubjectIds,
                ];
            }
        }
    }

    $classSlotPlan = shufflePreserve($classSlotPlan);

    foreach ($classSlotPlan as $slot) {
        $classId = (int) $slot['class_id'];
        $day = $slot['day'];
        $periodId = (int) $slot['period_id'];
        $preferredSubjectIds = array_map('intval', $slot['preferred_subject_ids'] ?? []);
        $period = $periodMap[$periodId] ?? null;

        if (!$period || isset($classBusy[$classId][$day][$periodId])) {
            continue;
        }

        $subjectList = $subjectsByClass[$classId] ?? [];
        if (!empty($preferredSubjectIds)) {
            $preferredSubjects = [];
            $otherSubjects = [];
            foreach ($subjectList as $candidateSubject) {
                if (in_array((int) ($candidateSubject['id'] ?? 0), $preferredSubjectIds, true)) {
                    $preferredSubjects[] = $candidateSubject;
                } else {
                    $otherSubjects[] = $candidateSubject;
                }
            }
            $subjectList = array_merge(shufflePreserve($preferredSubjects), shufflePreserve($otherSubjects));
        } else {
            $subjectList = shufflePreserve($subjectList);
        }
        if (empty($subjectList)) {
            continue;
        }

        $selected = null;
        $bestScore = PHP_INT_MAX;

        foreach ($subjectList as $candidate) {
            $teacherId = (int) $candidate['teacher_id'];
            $subjectId = (int) $candidate['id'];
            $targetCount = effectiveWeeklyLessonTarget($candidate);
            $maxPerDay = max(1, (int) ($candidate['max_lessons_per_day'] ?? 1));
            $weeklyCount = (int) ($subjectUsageByClass[$classId][$subjectId] ?? 0);
            $sameDayCount = (int) ($subjectDayUsageByClass[$classId][$day][$subjectId] ?? 0);

            if ($targetCount > 0 && $weeklyCount >= $targetCount) {
                continue;
            }
            if ($sameDayCount >= $maxPerDay) {
                continue;
            }
            if (isset($teacherBusy[$teacherId][$day][$periodId])) {
                continue;
            }
            if (!isTeacherAvailableForSlot($teacherAvailability, $teacherId, $day, $periodId)) {
                continue;
            }

            $remainingNeed = max(0, $targetCount - $weeklyCount);
            $randomJitter = mt_rand(0, 6);
            $score = ($sameDayCount * 120) + ($weeklyCount * 12) - ($remainingNeed * 18) + $randomJitter;

            if ($score < $bestScore) {
                $bestScore = $score;
                $selected = $candidate;
            }
        }

        if (!$selected) {
            $skipped++;
            continue;
        }

        $teacherId = (int) $selected['teacher_id'];
        $subjectId = (int) $selected['id'];
        $allowDouble = (int) ($selected['allow_double_period'] ?? 0) === 1;
        $targetCount = effectiveWeeklyLessonTarget($selected);
        $maxPerDay = max(1, (int) ($selected['max_lessons_per_day'] ?? 1));
        $weeklyCount = (int) ($subjectUsageByClass[$classId][$subjectId] ?? 0);
        $sameDayCount = (int) ($subjectDayUsageByClass[$classId][$day][$subjectId] ?? 0);
        $doubleNeighborId = null;
        if ($allowDouble && ($targetCount - ($weeklyCount + 1)) > 0 && ($sameDayCount + 1) < $maxPerDay) {
            foreach (getNeighborLessonPeriodIds($lessonPeriods, $periodId) as $neighborPeriodId) {
                $neighborPeriod = $periodMap[$neighborPeriodId] ?? null;
                if (!$neighborPeriod) {
                    continue;
                }
                if (isset($classBusy[$classId][$day][$neighborPeriodId])) {
                    continue;
                }
                if (isset($teacherBusy[$teacherId][$day][$neighborPeriodId])) {
                    continue;
                }
                if (!isTeacherAvailableForSlot($teacherAvailability, $teacherId, $day, $neighborPeriodId)) {
                    continue;
                }
                $doubleNeighborId = $neighborPeriodId;
                break;
            }
        }

        $periodIdsForRoom = $doubleNeighborId ? [$periodId, $doubleNeighborId] : [$periodId];
        $roomId = chooseRoomForSubject($selected, $roomsByType, $roomBusy, $day, $periodIdsForRoom, $doubleNeighborId !== null);

        $teacherBusy[$teacherId][$day][$periodId] = true;
        $classBusy[$classId][$day][$periodId] = true;
        if ($roomId) {
            $roomBusy[$roomId][$day][$periodId] = true;
        }
        $subjectUsageByClass[$classId][$subjectId] = $weeklyCount + 1;
        $subjectDayUsageByClass[$classId][$day][$subjectId] = $sameDayCount + 1;

        $insertStmt->execute([
            $planId,
            $classId,
            $subjectId,
            $teacherId,
            $day,
            $period['start_time'],
            $period['end_time'],
            $roomId,
            $plan['academic_year'] ?? date('Y'),
            $periodId,
            $plan['status'] === 'in_review' ? 'in_review' : 'draft',
            $doubleNeighborId !== null
                ? 'Auto-generated double lesson on ' . date('Y-m-d H:i')
                : 'Auto-generated by randomized spread on ' . date('Y-m-d H:i')
        ]);
        $generated++;

        if ($doubleNeighborId !== null) {
            $neighborPeriod = $periodMap[$doubleNeighborId] ?? null;
            if ($neighborPeriod) {
                $teacherBusy[$teacherId][$day][$doubleNeighborId] = true;
                $classBusy[$classId][$day][$doubleNeighborId] = true;
                if ($roomId) {
                    $roomBusy[$roomId][$day][$doubleNeighborId] = true;
                }
                $subjectUsageByClass[$classId][$subjectId] = (int) ($subjectUsageByClass[$classId][$subjectId] ?? 0) + 1;
                $subjectDayUsageByClass[$classId][$day][$subjectId] = (int) ($subjectDayUsageByClass[$classId][$day][$subjectId] ?? 0) + 1;

                $insertStmt->execute([
                    $planId,
                    $classId,
                    $subjectId,
                    $teacherId,
                    $day,
                    $neighborPeriod['start_time'],
                    $neighborPeriod['end_time'],
                    $roomId,
                    $plan['academic_year'] ?? date('Y'),
                    $doubleNeighborId,
                    $plan['status'] === 'in_review' ? 'in_review' : 'draft',
                    'Auto-generated double lesson on ' . date('Y-m-d H:i')
                ]);
                $generated++;
            }
        }
    }

    foreach ($subjectsByClass as $classId => $subjectList) {
        foreach ($subjectList as $subject) {
            $subjectId = (int) $subject['id'];
            $targetCount = effectiveWeeklyLessonTarget($subject);
            $scheduled = (int) ($subjectUsageByClass[$classId][$subjectId] ?? 0);
            if ($targetCount > $scheduled) {
                $unmetTargets += ($targetCount - $scheduled);
            }
        }
    }

    return [
        'generated' => $generated,
        'skipped' => $skipped,
        'classes_without_teacher' => count($classesWithoutTeacher),
        'unmet_targets' => $unmetTargets,
    ];
}

ensureTimetableSchema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['create_plan'])) {
            $title = trim((string) ($_POST['title'] ?? ''));
            $academicYear = trim((string) ($_POST['academic_year'] ?? date('Y')));
            $term = trim((string) ($_POST['term'] ?? 'Term 1'));
            $effectiveFrom = $_POST['effective_from'] ?: null;

            if ($title === '') {
                timetableRedirect('error', 'Timetable title is required.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO timetable_plans (title, academic_year, term, effective_from, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$title, $academicYear, $term, $effectiveFrom, $userId]);
            timetableRedirect('success', 'Timetable plan created successfully.', (int) $pdo->lastInsertId());
        }

        if (isset($_POST['save_period'])) {
            $planId = (int) ($_POST['plan_id'] ?? 0);
            $periodId = (int) ($_POST['period_id'] ?? 0);
            $label = trim((string) ($_POST['label'] ?? ''));
            $startTime = $_POST['start_time'] ?? '';
            $endTime = $_POST['end_time'] ?? '';
            $periodType = $_POST['period_type'] ?? 'lesson';
            $sortOrder = (int) ($_POST['sort_order'] ?? 1);

            if (!$planId || $label === '' || $startTime === '' || $endTime === '') {
                timetableRedirect('error', 'Fill in all period details.', $planId ?: null);
            }

            if ($periodId) {
                $stmt = $pdo->prepare("
                    UPDATE timetable_periods
                    SET label = ?, start_time = ?, end_time = ?, period_type = ?, sort_order = ?
                    WHERE id = ? AND plan_id = ?
                ");
                $stmt->execute([$label, $startTime, $endTime, $periodType, $sortOrder, $periodId, $planId]);
                $pdo->prepare("UPDATE timetable_lessons SET start_time = ?, end_time = ? WHERE period_id = ? AND plan_id = ?")
                    ->execute([$startTime, $endTime, $periodId, $planId]);
                timetableRedirect('success', 'Period updated successfully.', $planId);
            }

            $stmt = $pdo->prepare("
                INSERT INTO timetable_periods (plan_id, label, start_time, end_time, period_type, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$planId, $label, $startTime, $endTime, $periodType, $sortOrder]);
            timetableRedirect('success', 'Period added successfully.', $planId);
        }

        if (isset($_POST['delete_period'])) {
            $planId = (int) ($_POST['plan_id'] ?? 0);
            $periodId = (int) ($_POST['period_id'] ?? 0);

            $pdo->prepare("DELETE FROM timetable_lessons WHERE period_id = ? AND plan_id = ?")->execute([$periodId, $planId]);
            $pdo->prepare("DELETE FROM timetable_periods WHERE id = ? AND plan_id = ?")->execute([$periodId, $planId]);
            timetableRedirect('success', 'Period removed successfully.', $planId);
        }

        if (isset($_POST['save_room'])) {
            $roomId = (int) ($_POST['room_id'] ?? 0);
            $roomName = trim((string) ($_POST['room_name'] ?? ''));
            $roomNumber = trim((string) ($_POST['room_number'] ?? ''));
            $capacity = (int) ($_POST['capacity'] ?? 0);
            $roomType = $_POST['room_type'] ?? 'hall';
            $description = trim((string) ($_POST['description'] ?? ''));

            if ($roomName === '') {
                timetableRedirect('error', 'Room name is required.');
            }

            if ($roomId) {
                $stmt = $pdo->prepare("
                    UPDATE rooms
                    SET room_name = ?, room_number = ?, capacity = ?, room_type = ?, description = ?
                    WHERE id = ?
                ");
                $stmt->execute([$roomName, $roomNumber ?: null, $capacity ?: null, $roomType, $description ?: null, $roomId]);
                timetableRedirect('success', 'Room updated successfully.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO rooms (room_name, room_number, capacity, room_type, description)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$roomName, $roomNumber ?: null, $capacity ?: null, $roomType, $description ?: null]);
            timetableRedirect('success', 'Room added successfully.');
        }

        if (isset($_POST['save_class_availability'])) {
            $planId = (int) ($_POST['plan_id'] ?? 0);
            $availableClasses = $_POST['available_classes'] ?? [];
            $classNotes = $_POST['class_notes'] ?? [];

            $pdo->exec("DELETE FROM timetable_class_settings");
            $classesStmt = $pdo->query("SELECT id FROM classes WHERE COALESCE(is_active, 1) = 1");
            foreach ($classesStmt->fetchAll(PDO::FETCH_COLUMN) as $classId) {
                $isAvailable = in_array((string) $classId, array_map('strval', $availableClasses), true) ? 1 : 0;
                $notes = trim((string) ($classNotes[$classId] ?? ''));
                $stmt = $pdo->prepare("
                    INSERT INTO timetable_class_settings (class_id, is_available, notes, updated_by)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([(int) $classId, $isAvailable, $notes ?: null, $userId]);
            }
            timetableRedirect('success', 'Class availability updated.', $planId ?: null);
        }

        if (isset($_POST['save_subject_loads'])) {
            $planId = (int) ($_POST['plan_id'] ?? 0);
            $subjectLoads = $_POST['subject_loads'] ?? [];
            $subjectMaxPerDay = $_POST['subject_max_per_day'] ?? [];
            $subjectDoubleRules = $_POST['subject_allow_double'] ?? [];

            $stmt = $pdo->prepare("
                UPDATE subjects
                SET weekly_lessons_per_week = ?, max_lessons_per_day = ?, allow_double_period = ?
                WHERE id = ?
            ");
            foreach ($subjectLoads as $subjectId => $load) {
                $weeklyLoad = max(0, (int) $load);
                $maxPerDay = max(1, (int) ($subjectMaxPerDay[$subjectId] ?? 1));
                $allowDouble = isset($subjectDoubleRules[$subjectId]) ? 1 : 0;
                $stmt->execute([$weeklyLoad, $maxPerDay, $allowDouble, (int) $subjectId]);
            }

            timetableRedirect('success', 'Subject lesson targets updated.', $planId ?: null);
        }

        if (isset($_POST['save_teacher_availability'])) {
            $planId = (int) ($_POST['plan_id'] ?? 0);
            $blockedSlots = $_POST['blocked_teacher_slots'] ?? [];
            $availabilityNotes = $_POST['teacher_availability_notes'] ?? [];

            $pdo->exec("DELETE FROM timetable_teacher_availability");

            foreach ($availabilityNotes as $teacherId => $dayNotes) {
                foreach ($dayNotes as $day => $periodNotes) {
                    foreach ($periodNotes as $periodId => $note) {
                        $isBlocked = isset($blockedSlots[$teacherId][$day][$periodId]);
                        if (!$isBlocked && trim((string) $note) === '') {
                            continue;
                        }

                        $stmt = $pdo->prepare("
                            INSERT INTO timetable_teacher_availability (teacher_id, day_of_week, period_id, is_available, notes)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            (int) $teacherId,
                            $day,
                            (int) $periodId,
                            $isBlocked ? 0 : 1,
                            trim((string) $note) ?: null
                        ]);
                    }
                }
            }

            timetableRedirect('success', 'Teacher availability updated.', $planId ?: null);
        }

        if (isset($_POST['save_lesson'])) {
            $planId = (int) ($_POST['plan_id'] ?? 0);
            $lessonId = (int) ($_POST['lesson_id'] ?? 0);
            $classId = (int) ($_POST['class_id'] ?? 0);
            $subjectId = (int) ($_POST['subject_id'] ?? 0);
            $teacherId = (int) ($_POST['teacher_id'] ?? 0);
            $roomId = $_POST['room_id'] !== '' ? (int) $_POST['room_id'] : null;
            $dayOfWeek = $_POST['day_of_week'] ?? '';
            $periodId = (int) ($_POST['period_id'] ?? 0);
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if (!$planId || !$classId || !$subjectId || !$teacherId || !$periodId || !in_array($dayOfWeek, $days, true)) {
                timetableRedirect('error', 'Complete all lesson fields before saving.', $planId ?: null);
            }

            $periodStmt = $pdo->prepare("SELECT * FROM timetable_periods WHERE id = ? AND plan_id = ?");
            $periodStmt->execute([$periodId, $planId]);
            $period = $periodStmt->fetch(PDO::FETCH_ASSOC);

            if (!$period) {
                timetableRedirect('error', 'Selected period could not be found.', $planId);
            }
            if ($period['period_type'] !== 'lesson') {
                timetableRedirect('error', 'You can only place lessons inside lesson periods.', $planId);
            }

            $subjectStmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE id = ? AND class_id = ?");
            $subjectStmt->execute([$subjectId, $classId]);
            $subjectRow = $subjectStmt->fetch(PDO::FETCH_ASSOC);
            if (!$subjectRow) {
                timetableRedirect('error', 'Selected subject does not belong to the chosen class.', $planId);
            }

            $subjectName = (string) ($subjectRow['subject_name'] ?? '');

            $conflictStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM timetable_lessons
                WHERE plan_id = ?
                  AND day_of_week = ?
                  AND period_id = ?
                  AND id != ?
                  AND (
                        class_id = ?
                     OR teacher_id = ?
                     OR (? IS NOT NULL AND room_id = ?)
                  )
            ");
            $conflictStmt->execute([$planId, $dayOfWeek, $periodId, $lessonId, $classId, $teacherId, $roomId, $roomId]);
            if ((int) $conflictStmt->fetchColumn() > 0) {
                timetableRedirect('error', 'That slot already has a class, teacher, or room allocation conflict.', $planId);
            }

            if ($roomId) {
                $roomStmt = $pdo->prepare("SELECT room_type FROM rooms WHERE id = ? LIMIT 1");
                $roomStmt->execute([$roomId]);
                $roomType = $roomStmt->fetchColumn() ?: null;

                $lessonPeriodStmt = $pdo->prepare("
                    SELECT id
                    FROM timetable_periods
                    WHERE plan_id = ? AND period_type = 'lesson'
                    ORDER BY sort_order, start_time
                ");
                $lessonPeriodStmt->execute([$planId]);
                $neighborPeriods = getNeighborLessonPeriodIds($lessonPeriodStmt->fetchAll(PDO::FETCH_ASSOC), $periodId);
                $doubleCheckStmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM timetable_lessons
                    WHERE plan_id = ?
                      AND class_id = ?
                      AND subject_id = ?
                      AND day_of_week = ?
                      AND period_id IN (" . implode(',', array_fill(0, count($neighborPeriods) ?: 1, '?')) . ")
                      AND id != ?
                ");
                $doubleParams = [$planId, $classId, $subjectId, $dayOfWeek];
                $doubleParams = array_merge($doubleParams, !empty($neighborPeriods) ? $neighborPeriods : [0], [$lessonId]);
                $doubleCheckStmt->execute($doubleParams);
                $hasDoubleLesson = (int) $doubleCheckStmt->fetchColumn() > 0;

                if (!roomTypeIsAllowedForSubject($subjectName, $roomType, $hasDoubleLesson)) {
                    timetableRedirect(
                        'error',
                        $roomType === 'laboratory'
                            ? 'Only science subjects with a double lesson can use the laboratory.'
                            : 'Only computer studies double lessons can use the computer lab.',
                        $planId
                    );
                }
            }

            $status = 'draft';
            $planStatusStmt = $pdo->prepare("SELECT status, academic_year FROM timetable_plans WHERE id = ?");
            $planStatusStmt->execute([$planId]);
            $planRow = $planStatusStmt->fetch(PDO::FETCH_ASSOC);
            if ($planRow && in_array($planRow['status'], ['in_review', 'published'], true)) {
                $status = $planRow['status'];
            }

            if ($lessonId) {
                $stmt = $pdo->prepare("
                    UPDATE timetable_lessons
                    SET class_id = ?, subject_id = ?, teacher_id = ?, room_id = ?, day_of_week = ?, start_time = ?, end_time = ?,
                        academic_year = ?, period_id = ?, status = ?, notes = ?
                    WHERE id = ? AND plan_id = ?
                ");
                $stmt->execute([
                    $classId, $subjectId, $teacherId, $roomId, $dayOfWeek, $period['start_time'], $period['end_time'],
                    $planRow['academic_year'] ?? date('Y'), $periodId, $status, $notes ?: null, $lessonId, $planId
                ]);
                timetableRedirect('success', 'Lesson updated successfully.', $planId);
            }

            $stmt = $pdo->prepare("
                INSERT INTO timetable_lessons (
                    plan_id, class_id, subject_id, teacher_id, day_of_week, start_time, end_time,
                    room_id, academic_year, period_id, status, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $planId, $classId, $subjectId, $teacherId, $dayOfWeek, $period['start_time'], $period['end_time'],
                $roomId, $planRow['academic_year'] ?? date('Y'), $periodId, $status, $notes ?: null
            ]);
            timetableRedirect('success', 'Lesson added successfully.', $planId);
        }

        if (isset($_POST['delete_lesson'])) {
            $planId = (int) ($_POST['plan_id'] ?? 0);
            $lessonId = (int) ($_POST['lesson_id'] ?? 0);
            $pdo->prepare("DELETE FROM timetable_lessons WHERE id = ? AND plan_id = ?")->execute([$lessonId, $planId]);
            timetableRedirect('success', 'Lesson removed from the timetable.', $planId);
        }

        if (isset($_POST['generate_timetable'])) {
            $planId = (int) ($_POST['plan_id'] ?? 0);
            if (!$planId) {
                timetableRedirect('error', 'Select a timetable plan before generating.');
            }

            $pdo->beginTransaction();
            $result = generateTimetableDraft($pdo, $planId, $days, $userId);
            $pdo->commit();

            $message = 'System-generated draft created with ' . $result['generated'] . ' lessons.';
            if ($result['skipped'] > 0) {
                $message .= ' ' . $result['skipped'] . ' slots were left open because of teacher conflicts.';
            }
            if ($result['classes_without_teacher'] > 0) {
                $message .= ' ' . $result['classes_without_teacher'] . ' classes have subjects without assigned teachers.';
            }
            if ($result['unmet_targets'] > 0) {
                $message .= ' ' . $result['unmet_targets'] . ' subject periods could not be placed within the available slots.';
            }
            timetableRedirect('success', $message, $planId);
        }

        if (isset($_POST['submit_for_review'])) {
            $planId = (int) ($_POST['plan_id'] ?? 0);
            $lessonCountStmt = $pdo->prepare("SELECT COUNT(*) FROM timetable_lessons WHERE plan_id = ?");
            $lessonCountStmt->execute([$planId]);
            if ((int) $lessonCountStmt->fetchColumn() === 0) {
                timetableRedirect('error', 'Add lessons before sending the timetable for review.', $planId);
            }

            $pdo->prepare("UPDATE timetable_plans SET status = 'in_review', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
                ->execute([$userId, $planId]);
            $pdo->prepare("UPDATE timetable_lessons SET status = 'in_review' WHERE plan_id = ?")->execute([$planId]);
            timetableRedirect('success', 'Timetable moved to review.', $planId);
        }

        if (isset($_POST['publish_plan'])) {
            $planId = (int) ($_POST['plan_id'] ?? 0);
            $pdo->beginTransaction();

            $pdo->prepare("UPDATE timetable_plans SET status = 'archived' WHERE status = 'published' AND id != ?")->execute([$planId]);
            $pdo->prepare("UPDATE timetable_lessons SET status = 'archived' WHERE status = 'published' AND plan_id != ?")->execute([$planId]);
            $pdo->prepare("
                UPDATE timetable_plans
                SET status = 'published', published_by = ?, published_at = NOW(),
                    reviewed_by = COALESCE(reviewed_by, ?), reviewed_at = COALESCE(reviewed_at, NOW())
                WHERE id = ?
            ")->execute([$userId, $userId, $planId]);
            $pdo->prepare("UPDATE timetable_lessons SET status = 'published' WHERE plan_id = ?")->execute([$planId]);

            $notificationStmt = $pdo->prepare("
                SELECT DISTINCT c.class_teacher_id, c.class_name, tp.title
                FROM timetable_lessons tl
                JOIN classes c ON c.id = tl.class_id
                JOIN timetable_plans tp ON tp.id = tl.plan_id
                WHERE tl.plan_id = ? AND c.class_teacher_id IS NOT NULL
            ");
            $notificationStmt->execute([$planId]);
            foreach ($notificationStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (function_exists('createNotification')) {
                    createNotification(
                        'timetable',
                        'New Timetable Published',
                        'A new timetable for ' . $row['class_name'] . ' is now available for review and printing.',
                        'high',
                        'fas fa-calendar-alt',
                        'indigo',
                        $row['class_teacher_id'],
                        null,
                        $planId,
                        'timetable_plan'
                    );
                }
            }

            $pdo->commit();
            timetableRedirect('success', 'Timetable published and class teachers notified.', $planId);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        timetableRedirect('error', 'Timetable update failed: ' . $e->getMessage(), isset($_POST['plan_id']) ? (int) $_POST['plan_id'] : null);
    }
}

$plans = $pdo->query("
    SELECT tp.*,
           COALESCE((SELECT COUNT(*) FROM timetable_periods tpp WHERE tpp.plan_id = tp.id), 0) AS period_count,
           COALESCE((SELECT COUNT(*) FROM timetable_lessons tl WHERE tl.plan_id = tp.id), 0) AS lesson_count
    FROM timetable_plans tp
    ORDER BY FIELD(tp.status, 'published', 'in_review', 'draft', 'archived'), tp.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$selectedPlanId = (int) ($_GET['plan_id'] ?? 0);
if (!$selectedPlanId && !empty($plans)) {
    $selectedPlanId = (int) $plans[0]['id'];
}

$selectedPlan = null;
foreach ($plans as $plan) {
    if ((int) $plan['id'] === $selectedPlanId) {
        $selectedPlan = $plan;
        break;
    }
}

$classes = $pdo->query("
    SELECT c.*, u.full_name AS class_teacher_name,
           COALESCE(sc.student_count, 0) AS student_count
    FROM classes c
    LEFT JOIN users u ON u.id = c.class_teacher_id
    LEFT JOIN (
        SELECT class_id, COUNT(*) AS student_count
        FROM students
        GROUP BY class_id
    ) sc ON sc.class_id = c.id
    WHERE COALESCE(c.is_active, 1) = 1
    ORDER BY c.class_name
")->fetchAll(PDO::FETCH_ASSOC);

$teachers = $pdo->query("
    SELECT id, full_name
    FROM users
    WHERE role = 'teacher' AND status = 'active'
    ORDER BY full_name
")->fetchAll(PDO::FETCH_ASSOC);

$subjects = $pdo->query("
    SELECT s.*, c.class_name, u.full_name AS teacher_name
    FROM subjects s
    LEFT JOIN classes c ON c.id = s.class_id
    LEFT JOIN users u ON u.id = s.teacher_id
    ORDER BY c.class_name, s.subject_name
")->fetchAll(PDO::FETCH_ASSOC);

$rooms = $pdo->query("
    SELECT *
    FROM rooms
    ORDER BY FIELD(room_type, 'hall', 'laboratory', 'computer_lab', 'classroom', 'library', 'office'), room_name
")->fetchAll(PDO::FETCH_ASSOC);

$classSettings = [];
if (timetableTableExists($pdo, 'timetable_class_settings')) {
    foreach ($pdo->query("SELECT * FROM timetable_class_settings")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $classSettings[$row['class_id']] = $row;
    }
}

$teacherAvailability = [];
if (timetableTableExists($pdo, 'timetable_teacher_availability')) {
    foreach ($pdo->query("SELECT * FROM timetable_teacher_availability")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $teacherAvailability[$row['teacher_id']][$row['day_of_week']][$row['period_id']] = $row;
    }
}

$periods = [];
$lessons = [];
if ($selectedPlanId) {
    $periodStmt = $pdo->prepare("SELECT * FROM timetable_periods WHERE plan_id = ? ORDER BY sort_order, start_time");
    $periodStmt->execute([$selectedPlanId]);
    $periods = $periodStmt->fetchAll(PDO::FETCH_ASSOC);

    $lessonStmt = $pdo->prepare("
        SELECT tl.*, c.class_name, s.subject_name, u.full_name AS teacher_name, r.room_name, r.room_number, tp.label AS period_label
        FROM timetable_lessons tl
        JOIN classes c ON c.id = tl.class_id
        JOIN subjects s ON s.id = tl.subject_id
        LEFT JOIN users u ON u.id = tl.teacher_id
        LEFT JOIN rooms r ON r.id = tl.room_id
        LEFT JOIN timetable_periods tp ON tp.id = tl.period_id
        WHERE tl.plan_id = ?
        ORDER BY FIELD(tl.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'), tl.start_time
    ");
    $lessonStmt->execute([$selectedPlanId]);
    $lessons = $lessonStmt->fetchAll(PDO::FETCH_ASSOC);
}

$lessonMap = buildLessonCellMap($lessons);
$availableClasses = array_values(array_filter($classes, function ($class) use ($classSettings) {
    if (!isset($classSettings[$class['id']])) {
        return true;
    }
    return (int) $classSettings[$class['id']]['is_available'] === 1;
}));

$selectedClassViewId = (int) ($_GET['class_view_id'] ?? ($availableClasses[0]['id'] ?? 0));
$selectedTeacherViewId = (int) ($_GET['teacher_view_id'] ?? ($teachers[0]['id'] ?? 0));
$selectedClassView = null;
$selectedTeacherView = null;

foreach ($availableClasses as $availableClass) {
    if ((int) $availableClass['id'] === $selectedClassViewId) {
        $selectedClassView = $availableClass;
        break;
    }
}

foreach ($teachers as $teacherOption) {
    if ((int) $teacherOption['id'] === $selectedTeacherViewId) {
        $selectedTeacherView = $teacherOption;
        break;
    }
}

$publishedPlanCount = count(array_filter($plans, fn($plan) => $plan['status'] === 'published'));
$lessonCount = count($lessons);
$nonLessonCount = count(array_filter($periods, fn($period) => $period['period_type'] !== 'lesson'));
$schoolDisplayName = trim((string) getSystemSetting('school_name', SCHOOL_NAME));
$schoolLocation = trim((string) getSystemSetting('school_location', SCHOOL_LOCATION));
$schoolPhone = trim((string) getSystemSetting('school_phone', ''));
$schoolEmail = trim((string) getSystemSetting('school_email', ''));
$schoolMotto = trim((string) getSystemSetting('school_motto', ''));
$schoolLogoUrl = function_exists('getDynamicFaviconUrl') ? getDynamicFaviconUrl() : '../logo.png';
$pageTitle = 'Timetable Studio - ' . SCHOOL_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=DM+Sans:wght@400;500;700&display=swap');

        :root {
            --ink: #16213e;
            --slate: #52607a;
            --mist: #edf3ff;
            --line: rgba(22, 33, 62, 0.1);
            --gold: #f5b700;
            --gold-soft: #fff4c8;
            --teal: #137c8b;
            --mint: #d9fff6;
            --paper: #ffffff;
            --sky: linear-gradient(135deg, #fff2d8 0%, #dbeafe 45%, #d9fff6 100%);
            --card-shadow: 0 20px 45px rgba(22, 33, 62, 0.12);
            --radius: 24px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'DM Sans', sans-serif;
            background: var(--sky);
            color: var(--ink);
        }
        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 28px;
            min-height: calc(100vh - 70px);
        }
        .sidebar.collapsed ~ .main-content { margin-left: 70px; }
        .studio-shell, .editor-stack, .plan-list, .period-list, .room-list, .availability-list { display: grid; gap: 24px; }
        .hero, .panel, .grid-panel, .modal-sheet {
            background: rgba(255, 255, 255, 0.94);
            border: 1px solid rgba(255, 255, 255, 0.7);
            box-shadow: var(--card-shadow);
            border-radius: var(--radius);
            backdrop-filter: blur(14px);
        }
        .hero {
            padding: 28px;
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 24px;
            align-items: end;
        }
        .hero h1, .section-title, .plan-title, .modal-sheet h3 {
            font-family: 'Space Grotesk', sans-serif;
            margin: 0;
        }
        .hero h1 { font-size: 2.35rem; line-height: 1.05; }
        .hero p, .helper {
            color: var(--slate);
            font-size: 0.95rem;
        }
        .hero-actions, .header-actions, .pill-row, .toolbar, .modal-actions, .inline-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }
        .kpi, .plan-card, .availability-item, .room-item, .period-item {
            padding: 16px 18px;
            border-radius: 18px;
            background: #fff;
            border: 1px solid var(--line);
        }
        .kpi span {
            display: block;
            color: var(--slate);
            font-size: 0.88rem;
        }
        .kpi strong {
            display: block;
            margin-top: 4px;
            font-size: 1.7rem;
        }
        .btn {
            border: 0;
            border-radius: 14px;
            padding: 12px 18px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: transform 0.18s ease;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: var(--ink); color: #fff; }
        .btn-accent { background: var(--gold); color: var(--ink); }
        .btn-soft { background: var(--mist); color: var(--ink); }
        .btn-danger { background: #ffe4e8; color: #9f1239; }
        .btn-sm { padding: 9px 14px; font-size: 0.9rem; }
        .panel, .grid-panel { padding: 24px; }
        .header-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: center;
            margin-bottom: 18px;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--mist);
            color: var(--ink);
            font-weight: 700;
            font-size: 0.85rem;
        }
        .status-draft { background: #eef2ff; color: #4338ca; }
        .status-in_review { background: #fff4c8; color: #8a5a00; }
        .status-published { background: var(--mint); color: #0f766e; }
        .status-archived { background: #f3f4f6; color: #475569; }
        .layout-two {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 24px;
        }
        .plan-card.active {
            border-color: rgba(22, 33, 62, 0.35);
            box-shadow: inset 0 0 0 1px rgba(22, 33, 62, 0.1);
        }
        .stats-line {
            margin-top: 12px;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            color: var(--slate);
            font-size: 0.86rem;
        }
        .toolbar {
            justify-content: space-between;
            margin-bottom: 18px;
        }
        .toolbar form { display: flex; gap: 10px; flex-wrap: wrap; }
        select, input[type="text"], input[type="date"], input[type="time"], input[type="number"], textarea {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 11px 14px;
            font: inherit;
            color: var(--ink);
            background: #fff;
        }
        textarea { min-height: 92px; resize: vertical; }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }
        .form-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
        }
        .field label {
            display: block;
            margin-bottom: 8px;
            font-weight: 700;
        }
        .availability-item header, .room-item header, .period-item header {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            margin-bottom: 10px;
        }
        .timetable-wrap {
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: 22px;
        }
        .print-sheet {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 24px;
            padding: 22px;
        }
        .print-sheet-header {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            align-items: center;
            margin-bottom: 18px;
            padding-bottom: 16px;
            border-bottom: 2px solid rgba(22, 33, 62, 0.08);
        }
        .school-brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .school-brand img {
            width: 72px;
            height: 72px;
            border-radius: 18px;
            object-fit: cover;
            border: 1px solid rgba(22, 33, 62, 0.1);
            background: #fff;
        }
        .school-brand h3,
        .sheet-meta h4 {
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
        }
        .school-brand p,
        .sheet-meta p {
            margin: 4px 0 0;
            color: var(--slate);
            font-size: 0.9rem;
        }
        .sheet-meta {
            text-align: right;
        }
        .sheet-meta-grid {
            margin-top: 10px;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }
        .sheet-chip {
            background: #f8fbff;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 10px 12px;
        }
        .sheet-chip span {
            display: block;
            font-size: 0.78rem;
            color: var(--slate);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .sheet-chip strong {
            display: block;
            margin-top: 4px;
            font-size: 0.98rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 980px;
            background: #fff;
        }
        th, td {
            border-bottom: 1px solid var(--line);
            border-right: 1px solid var(--line);
            vertical-align: top;
            padding: 14px;
        }
        thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #fff9e8;
            font-family: 'Space Grotesk', sans-serif;
        }
        th:first-child, td:first-child {
            position: sticky;
            left: 0;
            z-index: 1;
            background: #f8fbff;
        }
        .time-head strong, .day-name { display: block; }
        .time-head span, .slot-meta {
            color: var(--slate);
            font-size: 0.82rem;
        }
        .slot-empty, .slot-special, .slot-lesson {
            min-height: 116px;
            border-radius: 18px;
            padding: 12px;
        }
        .slot-empty {
            display: block;
            width: 100%;
            min-height: 96px;
            background: transparent;
            border: 0;
            padding: 0;
            cursor: pointer;
        }
        .slot-special {
            background: var(--gold-soft);
            border: 1px solid rgba(245, 183, 0, 0.28);
        }
        .slot-lesson {
            background: linear-gradient(180deg, #e9f2ff 0%, #ffffff 100%);
            border: 1px solid rgba(19, 124, 139, 0.18);
        }
        .slot-lesson strong, .slot-special strong {
            display: block;
            margin-bottom: 4px;
        }
        .slot-chip {
            display: inline-block;
            margin-top: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(19, 124, 139, 0.12);
            color: var(--teal);
            font-size: 0.78rem;
            font-weight: 700;
        }
        .preview-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        .flash {
            padding: 14px 18px;
            border-radius: 16px;
            font-weight: 700;
        }
        .flash-success { background: var(--mint); color: #115e59; }
        .flash-error { background: #ffe4e8; color: #9f1239; }
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.54);
            z-index: 1200;
            padding: 28px;
            overflow-y: auto;
        }
        .modal.open { display: block; }
        .modal-sheet {
            max-width: 980px;
            margin: 24px auto;
            padding: 24px;
        }
        .modal-sheet.narrow { max-width: 680px; }
        .checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .checkbox input { width: auto; }
        .screen-only { display: block; }
        .print-only { display: none; }
        @page {
            size: landscape;
            margin: 10mm;
        }
        @media print {
            body {
                background: #fff;
                color: #000;
            }
            .main-content {
                margin: 0;
                padding: 0;
            }
            .screen-only,
            .hero,
            .plan-list,
            .preview-grid,
            .flash,
            .modal,
            .header-actions form,
            .header-actions button:not(.print-trigger),
            .toolbar,
            .slot-empty,
            .inline-actions {
                display: none !important;
            }
            .layout-two,
            .editor-stack,
            .studio-shell {
                display: block;
            }
            .panel,
            .grid-panel,
            .print-sheet,
            .timetable-wrap {
                border: 0;
                box-shadow: none;
                background: #fff;
                padding: 0;
                overflow: visible;
            }
            .print-only { display: block; }
            table {
                min-width: 0;
                table-layout: fixed;
                font-size: 11px;
            }
            th, td {
                padding: 6px 5px;
            }
            th:first-child,
            td:first-child,
            thead th {
                position: static;
            }
            .slot-lesson,
            .slot-special {
                min-height: 56px;
                padding: 6px;
                border-radius: 10px;
            }
            .slot-meta,
            .time-head span,
            .helper,
            .school-brand p,
            .sheet-meta p {
                font-size: 10px;
            }
            .print-sheet-header {
                gap: 12px;
                margin-bottom: 12px;
                padding-bottom: 10px;
            }
            .school-brand img {
                width: 56px;
                height: 56px;
            }
            .sheet-meta-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 6px;
            }
            .sheet-chip {
                padding: 7px 8px;
                border-radius: 10px;
            }
        }
        @media (max-width: 1200px) {
            .layout-two, .hero, .preview-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 16px; }
            .form-grid, .form-grid-3, .kpi-grid { grid-template-columns: 1fr; }
            .modal { padding: 14px; }
        }
    </style>
</head>
<body>
<?php include '../loader.php'; ?>
<?php include '../navigation.php'; ?>
<?php include '../sidebar.php'; ?>

<div class="main-content">
    <div class="studio-shell">
        <?php if (!empty($_SESSION['success'])): ?>
            <div class="flash flash-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['error'])): ?>
            <div class="flash flash-error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <section class="hero">
            <div>
                <div class="pill-row">
                    <span class="pill"><i class="fas fa-wand-magic-sparkles"></i> Admin Timetable Studio</span>
                    <span class="pill"><?php echo count($availableClasses); ?> available classes</span>
                </div>
                <h1>Create, review, and publish the whole school timetable from one workspace.</h1>
                <p>The admin now controls the full timetable lifecycle: define the teaching periods, set breaks, lunch, and games, prepare lessons, review the grid, and only publish when the school copy is ready.</p>
                <div class="hero-actions">
                    <button class="btn btn-primary" type="button" onclick="openModal('planModal')"><i class="fas fa-plus"></i> New Timetable Plan</button>
                    <?php if ($selectedPlan): ?>
                        <button class="btn btn-soft" type="button" onclick="openModal('periodModal')"><i class="fas fa-clock"></i> Manage Periods</button>
                        <button class="btn btn-soft" type="button" onclick="openModal('classModal')"><i class="fas fa-school"></i> Manage Classes</button>
                        <button class="btn btn-soft" type="button" onclick="openModal('subjectLoadModal')"><i class="fas fa-layer-group"></i> Subject Loads</button>
                        <button class="btn btn-soft" type="button" onclick="openModal('teacherAvailabilityModal')"><i class="fas fa-user-clock"></i> Teacher Availability</button>
                        <button class="btn btn-soft" type="button" onclick="openModal('roomModal')"><i class="fas fa-building"></i> Manage Labs & Halls</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="kpi-grid">
                <div class="kpi"><span>Timetable Plans</span><strong><?php echo count($plans); ?></strong></div>
                <div class="kpi"><span>Published Plans</span><strong><?php echo $publishedPlanCount; ?></strong></div>
                <div class="kpi"><span>Slots Built</span><strong><?php echo count($periods); ?></strong></div>
                <div class="kpi"><span>Lessons Allocated</span><strong><?php echo $lessonCount; ?></strong></div>
            </div>
        </section>

        <section class="layout-two">
            <aside class="panel">
                <div class="header-row">
                    <div>
                        <h2 class="section-title">Timetable Plans</h2>
                        <p class="helper">Draft, review, publish, and archive school timetable versions.</p>
                    </div>
                    <button class="btn btn-accent btn-sm" type="button" onclick="openModal('planModal')"><i class="fas fa-plus"></i> Add</button>
                </div>
                <div class="plan-list">
                    <?php if (empty($plans)): ?>
                        <div class="plan-card">
                            <strong>No timetable plan yet</strong>
                            <p>Create the first plan to start defining periods and lesson slots.</p>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($plans as $plan): ?>
                        <a class="plan-card <?php echo (int) $plan['id'] === $selectedPlanId ? 'active' : ''; ?>" href="timetable.php?plan_id=<?php echo (int) $plan['id']; ?>&class_view_id=<?php echo $selectedClassViewId; ?>&teacher_view_id=<?php echo $selectedTeacherViewId; ?>">
                            <div class="inline-actions">
                                <span class="pill status-<?php echo htmlspecialchars($plan['status']); ?>"><?php echo strtoupper(str_replace('_', ' ', $plan['status'])); ?></span>
                            </div>
                            <h3 class="plan-title" style="margin-top: 12px;"><?php echo htmlspecialchars($plan['title']); ?></h3>
                            <p><?php echo htmlspecialchars($plan['term']); ?> • <?php echo htmlspecialchars($plan['academic_year']); ?></p>
                            <div class="stats-line">
                                <span><?php echo (int) $plan['period_count']; ?> periods</span>
                                <span><?php echo (int) $plan['lesson_count']; ?> lessons</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </aside>

            <div class="editor-stack">
                <section class="grid-panel">
                    <div class="header-row">
                        <div>
                            <h2 class="section-title"><?php echo $selectedPlan ? htmlspecialchars($selectedPlan['title']) : 'No Plan Selected'; ?></h2>
                            <p class="helper">
                                <?php if ($selectedPlan): ?>
                                    <?php echo htmlspecialchars($selectedPlan['term']); ?> timetable • status: <?php echo htmlspecialchars(str_replace('_', ' ', $selectedPlan['status'])); ?> • <?php echo $nonLessonCount; ?> special slots configured
                                <?php else: ?>
                                    Create a timetable plan to unlock the editor.
                                <?php endif; ?>
                            </p>
                            <?php if ($selectedPlan): ?>
                                <p class="helper">Use <strong>Subject Loads</strong> and <strong>Teacher Availability</strong> to define weekly lesson targets, daily caps, double lessons, and blocked staff slots, then let <strong>Auto Generate</strong> build a draft from those rules.</p>
                            <?php endif; ?>
                        </div>
                        <?php if ($selectedPlan): ?>
                            <div class="header-actions">
                                <button class="btn btn-primary btn-sm" type="button" onclick="openLessonModal()"><i class="fas fa-plus"></i> Add Lesson</button>
                                <button class="btn btn-soft btn-sm print-trigger" type="button" onclick="printSelectedClassTimetable()"><i class="fas fa-print"></i> Print Class Timetable</button>
                                <form method="post">
                                    <input type="hidden" name="plan_id" value="<?php echo $selectedPlanId; ?>">
                                    <button class="btn btn-soft btn-sm" type="submit" name="generate_timetable"><i class="fas fa-gears"></i> Auto Generate</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="plan_id" value="<?php echo $selectedPlanId; ?>">
                                    <button class="btn btn-soft btn-sm" type="submit" name="submit_for_review"><i class="fas fa-check-double"></i> Submit for Review</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="plan_id" value="<?php echo $selectedPlanId; ?>">
                                    <button class="btn btn-accent btn-sm" type="submit" name="publish_plan"><i class="fas fa-bullhorn"></i> Publish</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($selectedPlan && !empty($periods) && !empty($availableClasses)): ?>
                        <div class="toolbar">
                            <form method="get">
                                <input type="hidden" name="plan_id" value="<?php echo $selectedPlanId; ?>">
                                <label class="field" style="min-width: 240px;">
                                    <span class="helper">Class timetable editor</span>
                                    <select name="class_view_id" onchange="this.form.submit()">
                                        <?php foreach ($availableClasses as $class): ?>
                                            <option value="<?php echo (int) $class['id']; ?>" <?php echo (int) $class['id'] === $selectedClassViewId ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($class['class_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="field" style="min-width: 240px;">
                                    <span class="helper">Teacher timetable preview</span>
                                    <select name="teacher_view_id" onchange="this.form.submit()">
                                        <?php foreach ($teachers as $teacher): ?>
                                            <option value="<?php echo (int) $teacher['id']; ?>" <?php echo (int) $teacher['id'] === $selectedTeacherViewId ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($teacher['full_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </form>
                        </div>

                        <div class="pill-row" style="margin-bottom: 18px;">
                            <span class="pill">
                                <i class="fas fa-school"></i>
                                Class View: <?php echo htmlspecialchars($selectedClassView['class_name'] ?? 'No class selected'); ?>
                            </span>
                            <span class="pill">
                                <i class="fas fa-chalkboard-teacher"></i>
                                Teacher View: <?php echo htmlspecialchars($selectedTeacherView['full_name'] ?? 'No teacher selected'); ?>
                            </span>
                        </div>

                        <div class="print-sheet" id="class-timetable-print-sheet">
                            <div class="print-sheet-header print-only">
                                <div class="school-brand">
                                    <img src="<?php echo htmlspecialchars($schoolLogoUrl); ?>" alt="School logo">
                                    <div>
                                        <h3><?php echo htmlspecialchars($schoolDisplayName); ?></h3>
                                        <p>
                                            <?php echo htmlspecialchars($schoolLocation ?: 'School timetable office'); ?>
                                            <?php if ($schoolPhone !== ''): ?> | <?php echo htmlspecialchars($schoolPhone); ?><?php endif; ?>
                                            <?php if ($schoolEmail !== ''): ?> | <?php echo htmlspecialchars($schoolEmail); ?><?php endif; ?>
                                        </p>
                                        <?php if ($schoolMotto !== ''): ?><p><?php echo htmlspecialchars($schoolMotto); ?></p><?php endif; ?>
                                    </div>
                                </div>
                                <div class="sheet-meta">
                                    <h4><?php echo htmlspecialchars($selectedPlan['title'] ?? 'Class Timetable'); ?></h4>
                                    <p><?php echo htmlspecialchars(($selectedPlan['term'] ?? 'Term') . ' • ' . ($selectedPlan['academic_year'] ?? date('Y'))); ?></p>
                                </div>
                            </div>

                            <div class="sheet-meta-grid print-only">
                                <div class="sheet-chip">
                                    <span>Class</span>
                                    <strong><?php echo htmlspecialchars($selectedClassView['class_name'] ?? 'N/A'); ?></strong>
                                </div>
                                <div class="sheet-chip">
                                    <span>Class Teacher</span>
                                    <strong><?php echo htmlspecialchars($selectedClassView['class_teacher_name'] ?? 'Not assigned'); ?></strong>
                                </div>
                                <div class="sheet-chip">
                                    <span>Learners</span>
                                    <strong><?php echo (int) ($selectedClassView['student_count'] ?? 0); ?></strong>
                                </div>
                                <div class="sheet-chip">
                                    <span>Printed</span>
                                    <strong><?php echo htmlspecialchars(date('d M Y H:i')); ?></strong>
                                </div>
                            </div>

                            <div class="timetable-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Day</th>
                                            <?php foreach ($periods as $period): ?>
                                                <th class="time-head">
                                                    <strong><?php echo htmlspecialchars($period['label']); ?></strong>
                                                    <span><?php echo htmlspecialchars(substr($period['start_time'], 0, 5) . ' - ' . substr($period['end_time'], 0, 5)); ?></span>
                                                </th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($days as $day): ?>
                                            <tr>
                                                <td>
                                                    <strong class="day-name"><?php echo htmlspecialchars($day); ?></strong>
                                                    <span class="slot-meta">Lessons and school activities</span>
                                                </td>
                                                <?php foreach ($periods as $period): ?>
                                                    <?php $cell = $lessonMap[$selectedClassViewId][$day][$period['id']] ?? null; ?>
                                                    <td>
                                                        <?php if ($period['period_type'] !== 'lesson'): ?>
                                                            <div class="slot-special">
                                                                <strong><?php echo htmlspecialchars($period['label']); ?></strong>
                                                                <span><?php echo ucfirst($period['period_type']); ?> set by admin</span>
                                                                <div class="slot-chip"><?php echo htmlspecialchars(substr($period['start_time'], 0, 5) . ' - ' . substr($period['end_time'], 0, 5)); ?></div>
                                                            </div>
                                                        <?php elseif ($cell): ?>
                                                            <div class="slot-lesson">
                                                                <strong><?php echo htmlspecialchars($cell['subject_name']); ?></strong>
                                                                <div><?php echo htmlspecialchars($cell['teacher_name'] ?: 'Teacher not assigned'); ?></div>
                                                                <div class="slot-meta">
                                                                    <?php
                                                                        $venueLabel = $cell['room_name'] ?: ($cell['class_name'] ?? 'Class venue');
                                                                        if (!empty($cell['room_name']) && !empty($cell['room_number'])) {
                                                                            $venueLabel .= ' • ' . $cell['room_number'];
                                                                        }
                                                                        echo htmlspecialchars($venueLabel);
                                                                    ?>
                                                                </div>
                                                                <?php if (!empty($cell['notes'])): ?><div class="slot-meta"><?php echo htmlspecialchars($cell['notes']); ?></div><?php endif; ?>
                                                                <div class="inline-actions screen-only" style="margin-top:10px;">
                                                                    <button class="btn btn-soft btn-sm" type="button" onclick='openLessonModal(<?php echo json_encode([
                                                                        "lesson_id" => (int) $cell["id"],
                                                                        "class_id" => (int) $cell["class_id"],
                                                                        "subject_id" => (int) $cell["subject_id"],
                                                                        "teacher_id" => (int) $cell["teacher_id"],
                                                                        "room_id" => $cell["room_id"] !== null ? (int) $cell["room_id"] : "",
                                                                        "day_of_week" => $cell["day_of_week"],
                                                                        "period_id" => (int) $cell["period_id"],
                                                                        "notes" => (string) ($cell["notes"] ?? "")
                                                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'>Edit</button>
                                                                    <form method="post">
                                                                        <input type="hidden" name="plan_id" value="<?php echo $selectedPlanId; ?>">
                                                                        <input type="hidden" name="lesson_id" value="<?php echo (int) $cell['id']; ?>">
                                                                        <button class="btn btn-danger btn-sm" type="submit" name="delete_lesson">Remove</button>
                                                                    </form>
                                                                </div>
                                                            </div>
                                                        <?php else: ?>
                                                            <button class="slot-empty screen-only" type="button" aria-label="Open empty lesson slot" onclick='openLessonModal(<?php echo json_encode([
                                                                "class_id" => $selectedClassViewId,
                                                                "day_of_week" => $day,
                                                                "period_id" => (int) $period["id"]
                                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'></button>
                                                            <div class="print-only">&nbsp;</div>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php elseif ($selectedPlan): ?>
                        <div class="availability-item">
                            <strong>Finish the setup to start generating the timetable.</strong>
                            <p class="helper">You need at least one available class and one period in this timetable plan before the grid can be built.</p>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($selectedPlan && !empty($periods)): ?>
                    <section class="preview-grid">
                        <div class="grid-panel">
                            <div class="header-row">
                                <div>
                                    <h2 class="section-title">Teacher Timetable Preview<?php echo !empty($selectedTeacherView['full_name']) ? ' - ' . htmlspecialchars($selectedTeacherView['full_name']) : ''; ?></h2>
                                    <p class="helper">This is how the published structure reads on the teacher side for <?php echo htmlspecialchars($selectedTeacherView['full_name'] ?? 'the selected teacher'); ?>.</p>
                                </div>
                            </div>
                            <div class="timetable-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Day</th>
                                            <?php foreach ($periods as $period): ?>
                                                <th class="time-head">
                                                    <strong><?php echo htmlspecialchars($period['label']); ?></strong>
                                                    <span><?php echo htmlspecialchars(substr($period['start_time'], 0, 5) . ' - ' . substr($period['end_time'], 0, 5)); ?></span>
                                                </th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($days as $day): ?>
                                            <tr>
                                                <td><strong class="day-name"><?php echo htmlspecialchars($day); ?></strong></td>
                                                <?php foreach ($periods as $period): ?>
                                                    <?php $cell = $lessonMap['teacher'][$selectedTeacherViewId][$day][$period['id']] ?? null; ?>
                                                    <td>
                                                        <?php if ($period['period_type'] !== 'lesson'): ?>
                                                            <div class="slot-special">
                                                                <strong><?php echo htmlspecialchars($period['label']); ?></strong>
                                                                <span><?php echo ucfirst($period['period_type']); ?></span>
                                                            </div>
                                                        <?php elseif ($cell): ?>
                                                            <div class="slot-lesson">
                                                                <strong><?php echo htmlspecialchars($cell['subject_name']); ?></strong>
                                                                <div><?php echo htmlspecialchars($cell['class_name']); ?></div>
                                                                <div class="slot-meta"><?php echo htmlspecialchars($cell['room_name'] ?: $cell['class_name']); ?></div>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="slot-empty"></div>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="grid-panel">
                            <div class="header-row">
                                <div>
                                    <h2 class="section-title">Setup Summary</h2>
                                    <p class="helper">Core inputs that shape generation and publication.</p>
                                </div>
                            </div>
                            <div class="period-list">
                                <?php foreach ($periods as $period): ?>
                                    <div class="period-item">
                                        <header>
                                            <strong><?php echo htmlspecialchars($period['label']); ?></strong>
                                            <span class="pill status-<?php echo $period['period_type'] === 'lesson' ? 'published' : 'in_review'; ?>">
                                                <?php echo strtoupper($period['period_type']); ?>
                                            </span>
                                        </header>
                                        <div class="helper"><?php echo htmlspecialchars(substr($period['start_time'], 0, 5) . ' - ' . substr($period['end_time'], 0, 5)); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<div class="modal" id="planModal">
    <div class="modal-sheet narrow">
        <div class="header-row">
            <h3>Create Timetable Plan</h3>
            <button class="btn btn-soft btn-sm" type="button" onclick="closeModal('planModal')">Close</button>
        </div>
        <form method="post" class="form-grid">
            <div class="field">
                <label>Plan Title</label>
                <input type="text" name="title" placeholder="Term 2 Master Timetable" required>
            </div>
            <div class="field">
                <label>Academic Year</label>
                <input type="text" name="academic_year" value="<?php echo htmlspecialchars(date('Y')); ?>" required>
            </div>
            <div class="field">
                <label>Term</label>
                <input type="text" name="term" value="Term 1" required>
            </div>
            <div class="field">
                <label>Effective From</label>
                <input type="date" name="effective_from">
            </div>
            <div class="modal-actions">
                <button class="btn btn-primary" type="submit" name="create_plan"><i class="fas fa-save"></i> Save Plan</button>
            </div>
        </form>
    </div>
</div>

<?php if ($selectedPlan): ?>
    <div class="modal" id="periodModal">
        <div class="modal-sheet">
            <div class="header-row">
                <div>
                    <h3>Manage Lesson Periods, Breaks, Lunch, and Games</h3>
                    <p class="helper">These slots define the horizontal time structure for the timetable grid.</p>
                </div>
                <button class="btn btn-soft btn-sm" type="button" onclick="closeModal('periodModal')">Close</button>
            </div>
            <form method="post" class="form-grid-3" style="margin-bottom: 20px;">
                <input type="hidden" name="plan_id" value="<?php echo $selectedPlanId; ?>">
                <div class="field"><label>Label</label><input type="text" name="label" placeholder="Period 1" required></div>
                <div class="field"><label>Start Time</label><input type="time" name="start_time" required></div>
                <div class="field"><label>End Time</label><input type="time" name="end_time" required></div>
                <div class="field">
                    <label>Type</label>
                    <select name="period_type">
                        <option value="lesson">Lesson</option>
                        <option value="break">Break</option>
                        <option value="lunch">Lunch</option>
                        <option value="games">Games</option>
                    </select>
                </div>
                <div class="field"><label>Order</label><input type="number" name="sort_order" value="<?php echo count($periods) + 1; ?>" required></div>
                <div class="field" style="display:flex;align-items:end;"><button class="btn btn-primary" type="submit" name="save_period"><i class="fas fa-plus"></i> Add Slot</button></div>
            </form>
            <div class="period-list">
                <?php foreach ($periods as $period): ?>
                    <div class="period-item">
                        <header>
                            <div>
                                <strong><?php echo htmlspecialchars($period['label']); ?></strong>
                                <div class="helper"><?php echo htmlspecialchars(substr($period['start_time'], 0, 5) . ' - ' . substr($period['end_time'], 0, 5)); ?></div>
                            </div>
                            <span class="pill status-<?php echo $period['period_type'] === 'lesson' ? 'published' : 'in_review'; ?>"><?php echo strtoupper($period['period_type']); ?></span>
                        </header>
                        <form method="post">
                            <input type="hidden" name="plan_id" value="<?php echo $selectedPlanId; ?>">
                            <input type="hidden" name="period_id" value="<?php echo (int) $period['id']; ?>">
                            <button class="btn btn-danger btn-sm" type="submit" name="delete_period">Delete Slot</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="modal" id="classModal">
        <div class="modal-sheet">
            <div class="header-row">
                <div>
                    <h3>Manage Available Classes</h3>
                    <p class="helper">Only checked classes appear in the timetable editor and publication workflow.</p>
                </div>
                <button class="btn btn-soft btn-sm" type="button" onclick="closeModal('classModal')">Close</button>
            </div>
            <form method="post">
                <input type="hidden" name="plan_id" value="<?php echo $selectedPlanId; ?>">
                <div class="availability-list">
                    <?php foreach ($classes as $class): ?>
                        <?php $setting = $classSettings[$class['id']] ?? ['is_available' => 1, 'notes' => '']; ?>
                        <div class="availability-item">
                            <header>
                                <div>
                                    <strong><?php echo htmlspecialchars($class['class_name']); ?></strong>
                                    <div class="helper"><?php echo htmlspecialchars($class['class_teacher_name'] ?: 'No class teacher assigned'); ?></div>
                                </div>
                                <label class="checkbox">
                                    <input type="checkbox" name="available_classes[]" value="<?php echo (int) $class['id']; ?>" <?php echo (int) $setting['is_available'] === 1 ? 'checked' : ''; ?>>
                                    Available
                                </label>
                            </header>
                            <textarea name="class_notes[<?php echo (int) $class['id']; ?>]" placeholder="Optional note for timetable planning"><?php echo htmlspecialchars((string) ($setting['notes'] ?? '')); ?></textarea>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="modal-actions" style="margin-top: 18px;">
                    <button class="btn btn-primary" type="submit" name="save_class_availability"><i class="fas fa-save"></i> Save Class Setup</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="subjectLoadModal">
        <div class="modal-sheet">
            <div class="header-row">
                <div>
                    <h3>Manage Subject Lessons Per Week</h3>
                    <p class="helper">These targets tell the auto-generator how many times each subject should appear in a week for its class.</p>
                </div>
                <button class="btn btn-soft btn-sm" type="button" onclick="closeModal('subjectLoadModal')">Close</button>
            </div>
            <form method="post">
                <input type="hidden" name="plan_id" value="<?php echo $selectedPlanId; ?>">
                <div class="availability-list">
                    <?php foreach ($subjects as $subject): ?>
                        <div class="availability-item">
                            <header>
                                <div>
                                    <strong><?php echo htmlspecialchars($subject['subject_name']); ?></strong>
                                    <div class="helper">
                                        <?php echo htmlspecialchars(($subject['class_name'] ?: 'No class') . ' • ' . ($subject['teacher_name'] ?: 'Teacher not assigned')); ?>
                                    </div>
                                </div>
                            </header>
                            <div class="form-grid-3">
                                <div>
                                    <label class="helper" for="subject_load_<?php echo (int) $subject['id']; ?>">Lessons per week</label>
                                    <input id="subject_load_<?php echo (int) $subject['id']; ?>" type="number" min="0" name="subject_loads[<?php echo (int) $subject['id']; ?>]" value="<?php echo (int) ($subject['weekly_lessons_per_week'] ?? 4); ?>">
                                </div>
                                <div>
                                    <label class="helper" for="subject_max_day_<?php echo (int) $subject['id']; ?>">Max per day</label>
                                    <input id="subject_max_day_<?php echo (int) $subject['id']; ?>" type="number" min="1" name="subject_max_per_day[<?php echo (int) $subject['id']; ?>]" value="<?php echo (int) ($subject['max_lessons_per_day'] ?? 1); ?>">
                                </div>
                                <div style="display:flex;align-items:end;">
                                    <label class="checkbox">
                                        <input type="checkbox" name="subject_allow_double[<?php echo (int) $subject['id']; ?>]" value="1" <?php echo !empty($subject['allow_double_period']) ? 'checked' : ''; ?>>
                                        Allow double lesson
                                    </label>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="modal-actions" style="margin-top: 18px;">
                    <button class="btn btn-primary" type="submit" name="save_subject_loads"><i class="fas fa-save"></i> Save Subject Loads</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="teacherAvailabilityModal">
        <div class="modal-sheet">
            <div class="header-row">
                <div>
                    <h3>Manage Teacher Availability</h3>
                    <p class="helper">Block the days and periods when a teacher cannot be scheduled. The generator will skip those slots automatically.</p>
                </div>
                <button class="btn btn-soft btn-sm" type="button" onclick="closeModal('teacherAvailabilityModal')">Close</button>
            </div>
            <form method="post">
                <input type="hidden" name="plan_id" value="<?php echo $selectedPlanId; ?>">
                <div class="availability-list">
                    <?php foreach ($teachers as $teacher): ?>
                        <div class="availability-item">
                            <header>
                                <div>
                                    <strong><?php echo htmlspecialchars($teacher['full_name']); ?></strong>
                                    <div class="helper">Set unavailable slots only. Unchecked slots remain schedulable.</div>
                                </div>
                            </header>
                            <div class="timetable-wrap">
                                <table style="min-width: 760px;">
                                    <thead>
                                        <tr>
                                            <th>Day</th>
                                            <?php foreach ($periods as $period): ?>
                                                <?php if ($period['period_type'] === 'lesson'): ?>
                                                    <th>
                                                        <?php echo htmlspecialchars($period['label']); ?><br>
                                                        <span class="slot-meta"><?php echo htmlspecialchars(substr($period['start_time'], 0, 5) . ' - ' . substr($period['end_time'], 0, 5)); ?></span>
                                                    </th>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($days as $day): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($day); ?></strong></td>
                                                <?php foreach ($periods as $period): ?>
                                                    <?php if ($period['period_type'] !== 'lesson') { continue; } ?>
                                                    <?php $availabilityRow = $teacherAvailability[$teacher['id']][$day][$period['id']] ?? null; ?>
                                                    <td>
                                                        <label class="checkbox" style="margin-bottom:8px;">
                                                            <input type="checkbox" name="blocked_teacher_slots[<?php echo (int) $teacher['id']; ?>][<?php echo htmlspecialchars($day); ?>][<?php echo (int) $period['id']; ?>]" value="1" <?php echo ($availabilityRow && (int) $availabilityRow['is_available'] === 0) ? 'checked' : ''; ?>>
                                                            Block
                                                        </label>
                                                        <input type="text" name="teacher_availability_notes[<?php echo (int) $teacher['id']; ?>][<?php echo htmlspecialchars($day); ?>][<?php echo (int) $period['id']; ?>]" value="<?php echo htmlspecialchars((string) ($availabilityRow['notes'] ?? '')); ?>" placeholder="Optional note">
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="modal-actions" style="margin-top: 18px;">
                    <button class="btn btn-primary" type="submit" name="save_teacher_availability"><i class="fas fa-save"></i> Save Teacher Availability</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="roomModal">
        <div class="modal-sheet">
            <div class="header-row">
                <div>
                    <h3>Manage Labs and Halls</h3>
                    <p class="helper">Use these spaces for science, computer sessions, assemblies, practicals, and shared lessons.</p>
                </div>
                <button class="btn btn-soft btn-sm" type="button" onclick="closeModal('roomModal')">Close</button>
            </div>
            <form method="post" class="form-grid-3" style="margin-bottom:20px;">
                <div class="field"><label>Room Name</label><input type="text" name="room_name" placeholder="Main Hall" required></div>
                <div class="field"><label>Room Code</label><input type="text" name="room_number" placeholder="H-01"></div>
                <div class="field"><label>Capacity</label><input type="number" name="capacity" min="0" value="0"></div>
                <div class="field">
                    <label>Type</label>
                    <select name="room_type">
                        <option value="hall">Hall</option>
                        <option value="laboratory">Laboratory</option>
                        <option value="computer_lab">Computer Lab</option>
                        <option value="classroom">Classroom</option>
                        <option value="library">Library</option>
                        <option value="office">Office</option>
                    </select>
                </div>
                <div class="field" style="grid-column: span 2;">
                    <label>Description</label>
                    <input type="text" name="description" placeholder="Assembly space, chemistry practical lab, games briefing hall...">
                </div>
                <div class="field" style="display:flex;align-items:end;"><button class="btn btn-primary" type="submit" name="save_room"><i class="fas fa-save"></i> Save Resource</button></div>
            </form>
            <div class="room-list">
                <?php foreach ($rooms as $room): ?>
                    <div class="room-item">
                        <header>
                            <div>
                                <strong><?php echo htmlspecialchars($room['room_name']); ?></strong>
                                <div class="helper"><?php echo htmlspecialchars(strtoupper((string) $room['room_type']) . ($room['room_number'] ? ' • ' . $room['room_number'] : '')); ?></div>
                            </div>
                            <span class="pill"><?php echo (int) $room['capacity']; ?> capacity</span>
                        </header>
                        <?php if (!empty($room['description'])): ?><div class="helper"><?php echo htmlspecialchars($room['description']); ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="modal" id="lessonModal">
        <div class="modal-sheet">
            <div class="header-row">
                <div>
                    <h3>Assign Lesson to Timetable Slot</h3>
                    <p class="helper">Pick the class, subject, teacher, room, day, and lesson period. Breaks, lunch, and games stay locked as admin-defined slots.</p>
                </div>
                <button class="btn btn-soft btn-sm" type="button" onclick="closeModal('lessonModal')">Close</button>
            </div>
            <form method="post" id="lessonForm">
                <input type="hidden" name="plan_id" value="<?php echo $selectedPlanId; ?>">
                <input type="hidden" name="lesson_id" id="lesson_id" value="">
                <div class="form-grid-3">
                    <div class="field">
                        <label>Class</label>
                        <select name="class_id" id="class_id" required>
                            <option value="">Select class</option>
                            <?php foreach ($availableClasses as $class): ?>
                                <option value="<?php echo (int) $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Subject</label>
                        <select name="subject_id" id="subject_id" required>
                            <option value="">Select subject</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option
                                    value="<?php echo (int) $subject['id']; ?>"
                                    data-class-id="<?php echo (int) $subject['class_id']; ?>"
                                    data-teacher-id="<?php echo (int) ($subject['teacher_id'] ?? 0); ?>"
                                    data-subject-name="<?php echo htmlspecialchars((string) $subject['subject_name']); ?>"
                                    data-allow-double="<?php echo !empty($subject['allow_double_period']) ? '1' : '0'; ?>"
                                >
                                    <?php echo htmlspecialchars($subject['class_name'] . ' • ' . $subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Teacher</label>
                        <select name="teacher_id" id="teacher_id" required>
                            <option value="">Select teacher</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?php echo (int) $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Room / Hall / Lab</label>
                        <select name="room_id" id="room_id">
                            <option value="">No room selected</option>
                            <?php foreach ($rooms as $room): ?>
                                <option value="<?php echo (int) $room['id']; ?>" data-room-type="<?php echo htmlspecialchars((string) $room['room_type']); ?>">
                                    <?php echo htmlspecialchars($room['room_name'] . ($room['room_number'] ? ' • ' . $room['room_number'] : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Day</label>
                        <select name="day_of_week" id="day_of_week" required>
                            <option value="">Select day</option>
                            <?php foreach ($days as $day): ?>
                                <option value="<?php echo htmlspecialchars($day); ?>"><?php echo htmlspecialchars($day); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Lesson Period</label>
                        <select name="period_id" id="period_id" required>
                            <option value="">Select period</option>
                            <?php foreach ($periods as $period): ?>
                                <?php if ($period['period_type'] === 'lesson'): ?>
                                    <option value="<?php echo (int) $period['id']; ?>">
                                        <?php echo htmlspecialchars($period['label'] . ' • ' . substr($period['start_time'], 0, 5) . ' - ' . substr($period['end_time'], 0, 5)); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field" style="margin-top:14px;">
                    <label>Notes</label>
                    <textarea name="notes" id="notes" placeholder="Optional: double practical, remedial, shared hall session..."></textarea>
                </div>
                <div class="modal-actions" style="margin-top:18px;">
                    <button class="btn btn-primary" type="submit" name="save_lesson"><i class="fas fa-save"></i> Save Lesson</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<script>
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.add('open');
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.remove('open');
}

window.addEventListener('click', function (event) {
    document.querySelectorAll('.modal.open').forEach(function (modal) {
        if (event.target === modal) {
            modal.classList.remove('open');
        }
    });
});

function filterSubjectsByClass() {
    const classSelect = document.getElementById('class_id');
    const subjectSelect = document.getElementById('subject_id');
    if (!classSelect || !subjectSelect) return;

    const classId = classSelect.value;
    const selectedValue = subjectSelect.value;

    Array.from(subjectSelect.options).forEach(function (option, index) {
        if (index === 0) return;
        const match = !classId || option.dataset.classId === classId;
        option.hidden = !match;
    });

    if (selectedValue) {
        const selectedOption = subjectSelect.querySelector('option[value="' + selectedValue + '"]');
        if (selectedOption && selectedOption.hidden) {
            subjectSelect.value = '';
        }
    }

    filterRoomsBySubject();
}

function subjectNeedsScienceLab(name) {
    const value = (name || '').toLowerCase();
    return value.includes('chem') || value.includes('phys') || value.includes('bio') || value.includes('science');
}

function subjectNeedsComputerLab(name) {
    const value = (name || '').toLowerCase();
    return value.includes('computer') || value.includes('ict') || value.includes('coding');
}

function filterRoomsBySubject() {
    const subjectSelect = document.getElementById('subject_id');
    const roomSelect = document.getElementById('room_id');
    if (!subjectSelect || !roomSelect) return;

    const subjectOption = subjectSelect.options[subjectSelect.selectedIndex];
    const subjectName = subjectOption?.dataset?.subjectName || '';
    const allowDouble = subjectOption?.dataset?.allowDouble === '1';
    const allowScienceLab = subjectNeedsScienceLab(subjectName) && allowDouble;
    const allowComputerLab = subjectNeedsComputerLab(subjectName) && allowDouble;

    Array.from(roomSelect.options).forEach(function (option, index) {
        if (index === 0) return;

        const roomType = option.dataset.roomType || '';
        const hideScienceLab = roomType === 'laboratory' && !allowScienceLab;
        const hideComputerLab = roomType === 'computer_lab' && !allowComputerLab;
        option.hidden = hideScienceLab || hideComputerLab;

        if (option.hidden && option.selected) {
            roomSelect.value = '';
        }
    });
}

function openLessonModal(payload) {
    const data = payload || {};
    document.getElementById('lesson_id').value = data.lesson_id || '';
    document.getElementById('class_id').value = data.class_id || '';
    document.getElementById('teacher_id').value = data.teacher_id || '';
    document.getElementById('room_id').value = data.room_id || '';
    document.getElementById('day_of_week').value = data.day_of_week || '';
    document.getElementById('period_id').value = data.period_id || '';
    document.getElementById('notes').value = data.notes || '';
    filterSubjectsByClass();
    document.getElementById('subject_id').value = data.subject_id || '';
    filterRoomsBySubject();
    openModal('lessonModal');
}

function printSelectedClassTimetable() {
    window.print();
}

document.getElementById('class_id')?.addEventListener('change', filterSubjectsByClass);
document.getElementById('subject_id')?.addEventListener('change', function () {
    const selected = this.options[this.selectedIndex];
    if (selected && selected.dataset.teacherId && !document.getElementById('teacher_id').value) {
        document.getElementById('teacher_id').value = selected.dataset.teacherId;
    }
    filterRoomsBySubject();
});
</script>
</body>
</html>
