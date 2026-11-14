<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tinuum Networks Inc.
// Shared "do these two lines follow the same route?" geometry.
//
// Extracted from api/features.php (which draws strand/span/conduit that share a
// route as one cased line) so the work-order inheritance in api/span.php and
// api/strand.php decides "runs along" exactly the same way the map draws it.

// ── Route-coincidence helpers (cased-hybrid line rendering) ───────────────────
// Strand / span / conduit that follow the SAME route are drawn as one cased line
// on the strand, and the redundant copies are hidden. Deciding "same route" with
// a bare ST_DWithin is wrong: a conduit riser that merely touches one pole of a
// strand tests true, which both paints the whole strand span orange and hides the
// riser itself. Instead require that most of a line's LENGTH runs alongside the
// other — i.e. lies inside a ~12 m corridor around it.
const COINCIDE_TOL  = 0.00012;   // ~12 m in degrees at this latitude
const COINCIDE_FRAC = 0.7;       // ≥70% of the line's length must run alongside

// Buffer used only to work out how much of a covered line to still DRAW, never to
// decide coverage. It has to be much tighter than COINCIDE_TOL: a closure set back
// from the pole line sits well inside 12 m, so differencing against the coverage
// corridor leaves nothing and the line disappears completely.
const COINCIDE_DRAW_TOL = 0.00003;  // ~3 m in degrees at this latitude

// Max heading difference (degrees) for two lines to count as "same route". Being
// inside the 12 m corridor isn't enough: a short lateral/riser (a conduit or drop
// span from a pole to a nearby closure) shares a pole with the strand chain and so
// sits inside the buffer, but heads off at an angle. Without a heading test it gets
// absorbed into the strand's cased hybrid (painted along the poles) and hidden, so
// it vanishes from where it was actually drawn. 25° admits the few-degree wobble of
// parallel-but-offset infrastructure while rejecting laterals.
const COINCIDE_MAX_DEG = 25;

/**
 * SQL boolean: does the segment ($xStart → $xEnd) run parallel to line $y at the
 * place where it meets $y? Heading is compared against $y's LOCAL bearing there —
 * the bearing between the projections of $xStart and $xEnd onto $y — so a route
 * that curves along the poles still reads as parallel along its whole length.
 * $y must be a simple LineString to project onto; multi-part geometry can't be, so
 * it falls back to "parallel" (buffer-only, the old behaviour).
 */
function features_parallel_local(string $xStart, string $xEnd, string $y): string
{
    $deg = COINCIDE_MAX_DEG;
    $m   = "ST_LineMerge({$y})";
    return "(GeometryType({$m}) <> 'LINESTRING'
             OR (90 - abs(abs(mod((
                  degrees(ST_Azimuth({$xStart}, {$xEnd})) - degrees(ST_Azimuth(
                    ST_LineInterpolatePoint({$m}, ST_LineLocatePoint({$m}, {$xStart})),
                    ST_LineInterpolatePoint({$m}, ST_LineLocatePoint({$m}, {$xEnd}))))
                )::numeric, 180)) - 90)) < {$deg})";
}

/** SQL predicate: does most of line $a run alongside line $b (and roughly parallel to it)? */
function features_runs_alongside(string $a, string $b): string
{
    $tol  = COINCIDE_TOL;
    $frac = COINCIDE_FRAC;
    // ST_DWithin first so the spatial index still prefilters candidates.
    return "(ST_DWithin({$a}, {$b}, {$tol})
             AND ST_Length({$a}) > 0
             AND ST_Length(ST_Intersection(ST_Buffer({$b}, {$tol}), {$a}))
                 >= {$frac} * ST_Length({$a})
             AND " . features_parallel_local("ST_StartPoint({$a})", "ST_EndPoint({$a})", $b) . ")";
}

