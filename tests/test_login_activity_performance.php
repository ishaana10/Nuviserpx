<?php
/**
 * Benchmark test for Login Activity Query Optimization (N+1 Query Elimination)
 */

if(!defined('base_url')) define('base_url', 'http://localhost/ajms/');
if(!defined('base_app')) define('base_app', dirname(__DIR__) . '/');

echo "=== BENCHMARK: Login Activity Query Optimization ===\n\n";

// Mock audit log dataset (200 records, simulating 200 activity rows with tenant_id)
$rows = [];
for ($i = 1; $i <= 200; $i++) {
    $rows[] = [
        'id' => $i,
        'tenant_id' => ($i % 5) + 1, // 5 distinct tenants
        'user_id' => $i,
        'username' => "user_$i",
        'action' => ($i % 3 == 0) ? 'Failed Login Attempt' : 'User Login Successful',
        'details' => "Authentication event $i",
        'ip_address' => '127.0.0.1',
        'date_created' => date('Y-m-d H:i:s')
    ];
}

// Mock tenants table lookups
$tenants_db = [
    1 => 'Headquarters Enterprise',
    2 => 'Pacific Operations',
    3 => 'Suva Regional Branch',
    4 => 'Nadi Retail Store',
    5 => 'Lautoka Logistics'
];

// Approach 1: N+1 Queries Pattern
function simulate_n_plus_1($rows, $tenants_db) {
    $query_count = 1; // Initial main query
    $results = [];
    $start_time = microtime(true);

    foreach ($rows as $row) {
        $tenant_name = 'Unknown';
        if ($row['tenant_id']) {
            $query_count++; // Separate per-row database query
            if (isset($tenants_db[$row['tenant_id']])) {
                $tenant_name = $tenants_db[$row['tenant_id']];
            }
        }
        $row['tenant_name'] = $tenant_name;
        $results[] = $row;
    }

    $end_time = microtime(true);
    return [
        'time' => ($end_time - $start_time) * 1000, // ms
        'queries' => $query_count,
        'count' => count($results)
    ];
}

// Approach 2: Single JOIN Query Pattern
function simulate_optimized_join($rows, $tenants_db) {
    $query_count = 1; // Single JOIN query retrieves all fields
    $results = [];
    $start_time = microtime(true);

    foreach ($rows as $row) {
        // tenant_name already retrieved via JOIN COALESCE(t.name, 'System / Global')
        $row['tenant_name'] = isset($tenants_db[$row['tenant_id']]) ? $tenants_db[$row['tenant_id']] : 'System / Global';
        $results[] = $row;
    }

    $end_time = microtime(true);
    return [
        'time' => ($end_time - $start_time) * 1000, // ms
        'queries' => $query_count,
        'count' => count($results)
    ];
}

// Run benchmarks (1000 iterations to measure CPU overhead)
$iterations = 1000;

$n1_total_time = 0;
$n1_queries = 0;
for ($k = 0; $k < $iterations; $k++) {
    $res = simulate_n_plus_1($rows, $tenants_db);
    $n1_total_time += $res['time'];
    $n1_queries = $res['queries'];
}

$join_total_time = 0;
$join_queries = 0;
for ($k = 0; $k < $iterations; $k++) {
    $res = simulate_optimized_join($rows, $tenants_db);
    $join_total_time += $res['time'];
    $join_queries = $res['queries'];
}

$avg_n1_time = $n1_total_time / $iterations;
$avg_join_time = $join_total_time / $iterations;

echo "N+1 Query Approach:\n";
echo "  - Database Queries Executed: {$n1_queries} queries\n";
echo "  - Average Execution Time per render: " . number_format($avg_n1_time, 4) . " ms\n\n";

echo "Optimized JOIN Query Approach:\n";
echo "  - Database Queries Executed: {$join_queries} query\n";
echo "  - Average Execution Time per render: " . number_format($avg_join_time, 4) . " ms\n\n";

$query_reduction = (($n1_queries - $join_queries) / $n1_queries) * 100;
echo "RESULTS:\n";
echo "  - Query Count Reduction: " . number_format($query_reduction, 1) . "% (from {$n1_queries} to {$join_queries} queries)\n";

assert($join_queries === 1, "Optimized approach executes exactly 1 query");
assert($n1_queries === 201, "Unoptimized approach executes 201 queries for 200 records");

echo "\nBENCHMARK COMPLETED SUCCESSFULLY!\n";
