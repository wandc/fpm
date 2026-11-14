<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// public/pages/home.php
/** @var \FPM\App\Template $PAGE */

// Map is the centre of the app — redirect GET visits straight to it.
// POST visits (logout form) are allowed to continue so the handler runs.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Location: /index.php?page=map');
    exit;
}

$PAGE->setTitle('Home');

$PAGE->setContentRenderer(function () {
    ob_start();
    ?>
    <div class="row g-3">

        <!-- Closures -->
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title">Closures</h5>
                    <p class="text-muted mb-3">
                        Add / edit FOSC closures, view map, timeline, and splices for each closure.
                    </p>
                    <a class="btn btn-primary mt-auto" href="/index.php?page=closure">
                        Open Closures
                    </a>
                </div>
            </div>
        </div>

        <!-- Timeline -->
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title">Timeline</h5>
                    <p class="text-muted mb-3">
                        Global feed of timeline entries and photo uploads across all closures.
                    </p>
                    <a class="btn btn-outline-primary mt-auto" href="/index.php?page=timeline">
                        Open Timeline
                    </a>
                </div>
            </div>
        </div>

        <!-- Cable Types -->
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title">Cable Types</h5>
                    <p class="text-muted mb-3">
                        Manage the catalog of cable types used by cable spans (sheath size, notes, etc.).
                    </p>
                    <a class="btn btn-outline-primary mt-auto" href="/index.php?page=cables">
                        Manage Cable Types
                    </a>
                </div>
            </div>
        </div>

        <!-- Cable Spans -->
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title">Cable Spans</h5>
                    <p class="text-muted mb-3">
                        Create and maintain spans between closures. Geometry is edited in QGIS;
                        this manages IDs, FOSC endpoints, and notes.
                    </p>
                    <a class="btn btn-primary mt-auto" href="/index.php?page=cable_spans">
                        Manage Spans
                    </a>
                </div>
            </div>
        </div>

        <!-- Splice Manager -->
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title">Splice Manager</h5>
                    <p class="text-muted mb-3">
                        Detailed splice manager for a closure: enter splice ranges, view
                        splicing plan / as-built, and visualize charts.
                    </p>
                    <a class="btn btn-outline-primary mt-auto" href="/index.php?page=splices">
                        Open Splice Manager
                    </a>
                </div>
            </div>
        </div>

        <!-- Add Timeline Entry -->
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title">Add Timeline Entry</h5>
                    <p class="text-muted mb-3">
                        Post a note and/or photos not tied to a specific closure, or link them later.
                    </p>
                    <a class="btn btn-outline-secondary mt-auto" href="/index.php?page=upload">
                        New Entry
                    </a>
                </div>
            </div>
        </div>

    </div>
    <?php
    return ob_get_clean();
});
