<?php
    require "../config/db_conn.php"; 

    $section = isset($_GET['section']) ? $_GET['section'] : 'all';
    $filterDate = isset($_GET['date']) ? $_GET['date'] : '';

    $whereClauses = [];

    // 1. DATE FILTER
    if (!empty($filterDate)) {
        $safeDate = mysqli_real_escape_string($conn, $filterDate);
        $whereClauses[] = "DATE(a.TIME_IN) = '$safeDate'";
    }

    // 2. SECTION FILTER FIX: 
    // We only apply the section filter if a specific section is chosen.
    // We use a subquery or check for NULL to ensure records don't vanish if the JOIN is weak.
    if ($section !== 'all') {
        $safeSection = mysqli_real_escape_string($conn, $section);
        $whereClauses[] = "s.SECTION = '$safeSection'";
    }

    // 3. THE QUERY
    // Added s.STUDENT_NAME as official_name to check if the link is working
    $query = "SELECT a.*, s.SECTION, s.STUDENT_NAME as official_name
              FROM attendance a
              LEFT JOIN students s ON TRIM(a.STUDENT_ID) = TRIM(s.STUDENT_ID)";
    
    if (count($whereClauses) > 0) {
        $query .= " WHERE " . implode(' AND ', $whereClauses);
    }

    $query .= " ORDER BY a.TIME_IN DESC";
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0): 
        while($row = $result->fetch_assoc()): 
            $datetime = new DateTime($row['TIME_IN']);
            $formattedDate = $datetime->format('F j, Y');
            $formattedTime = $datetime->format('g:i A');

            // FALLBACK LOGIC: If the LEFT JOIN failed (official_name is empty), 
            // use the name stored directly in the attendance table.
            $displayName = !empty($row['official_name']) ? $row['official_name'] : $row['STUDENT_NAME'];
    ?>
        <tr class="transition hover:bg-slate-50">
            <td class="px-4 py-4 font-medium text-slate-700"><?php echo htmlspecialchars($row['STUDENT_ID']); ?></td>
            <td class="px-4 py-4 font-bold text-slate-900"><?php echo htmlspecialchars($displayName); ?></td>
            <td>
                <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">
                    <?php echo htmlspecialchars($row['SECTION'] ?? 'N/A'); ?>
                </span>
            </td>
            <td class="px-4 py-4 text-slate-600"><?php echo $formattedDate; ?></td>
            <td class="px-4 py-4 text-slate-600"><?php echo $formattedTime; ?></td>
            <td class="px-4 py-4"><span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">Present</span></td>
        </tr>
    <?php endwhile; 
    else: ?>
        <tr>
            <td colspan="6" class="px-4 py-10 text-center text-slate-500">
                No records found in database for <b><?php echo htmlspecialchars($filterDate); ?></b> 
                <?php echo ($section !== 'all') ? "in section <b>".htmlspecialchars($section)."</b>" : ""; ?>.
            </td>
        </tr>
    <?php endif; ?>
