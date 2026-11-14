<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// phpHelpers/construction_stage.helper.php — shared vocabulary for Tinuum construction stages
//
// Mirrors the app.construction_stage enum so the pages can render the picker and
// the API can validate a POST against the same list, in the same way
// bell_permit.helper.php mirrors app.bell_permit_status. Keep the order matching
// the enum: it is the construction sequence and the <select> relies on it.
//
// The stage names the last thing COMPLETED, and counts itself: at 'splicing',
// make-ready, fibre install and splicing are all earned. See
// bom_stage_completed_categories() for how that turns into money.

const CONSTRUCTION_STAGES = [
    'not_started', 'make_ready', 'fibre_install', 'splicing', 'commissioning', 'complete',
];

// Each stage names the last thing FINISHED, so every label says so explicitly —
// "Splicing" alone was read as "splicing is underway", which is the opposite.
const CONSTRUCTION_STAGE_LABEL = [
    'not_started'   => 'Not started',
    'make_ready'    => 'Make-Ready complete',
    'fibre_install' => 'Fibre Install complete',
    'splicing'      => 'Splicing complete',
    'commissioning' => 'Commissioning complete',
    'complete'      => 'Construction complete',
];

// Bootstrap contextual class for the stage badge.
const CONSTRUCTION_STAGE_CLASS = [
    'not_started'   => 'secondary',
    'make_ready'    => 'warning text-dark',
    'fibre_install' => 'info',
    'splicing'      => 'primary',
    'commissioning' => 'primary',
    'complete'      => 'success',
];

/**
 * The BOM category each stage corresponds to, for the "what is complete" rule.
 * 'not_started' and 'complete' are bookends with no category of their own.
 */
const CONSTRUCTION_STAGE_CATEGORY = [
    'make_ready'    => 'make_ready',
    'fibre_install' => 'fibre_install',
    'splicing'      => 'splicing',
    'commissioning' => 'commissioning',
];

/**
 * Human label for a stage, tolerant of an unknown value.
 */
function construction_stage_label(?string $stage): string
{
    return CONSTRUCTION_STAGE_LABEL[$stage ?? ''] ?? ucfirst(str_replace('_', ' ', (string)$stage));
}

/**
 * Bootstrap badge class for a stage.
 */
function construction_stage_class(?string $stage): string
{
    return CONSTRUCTION_STAGE_CLASS[$stage ?? ''] ?? 'secondary';
}
