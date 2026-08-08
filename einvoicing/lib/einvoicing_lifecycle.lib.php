<?php
/* Copyright (C) 2026	Charles Peltier

 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    lib/einvoicing_lifecycle.lib.php
 * \ingroup einvoicing
 * \brief   Sequence-diagram ("chronogramme") rendering for the e-invoicing lifecycle of an invoice.
 *
 * Ported from the LemonSuperPDP module (tab_lifecycle.php / lsp_render_svg and friends), generalized
 * from a single-provider model (explicit 'flux' column, literal AFNOR/SUPER PDP status strings) to the
 * einvoicing module's multi-provider llx_einvoicing_lifecycle_msg table, which only stores a numeric
 * XP Z12-012 status code (EInvoicing::STATUS_*) plus a direction ('in'/'out'). The Factur-X-specific
 * local events (facturx:generated/error) from the original module have no equivalent here and are
 * intentionally dropped; e-invoice file generation is already covered by the "current status" block.
 */

/**
 * Swimlane (actor) a lifecycle event belongs to: 'fournisseur' (Dolibarr/seller side, us), 'pdp'
 * (network / Access Point layer) or 'client' (buyer side).
 *
 * Direction gives the primary signal (out = emitted by us, in = received back). For an 'in' event, the
 * XP Z12-012 status range refines it between the network acknowledging our submission (200-203, and the
 * 213 rejection) and the buyer's own processing (204 and above).
 *
 * @param int    $status    Lifecycle status code (EInvoicing::STATUS_*)
 * @param string $direction 'in' or 'out'
 * @return string 'fournisseur'|'pdp'|'client'
 */
function einvoicingLifecycleFlux($status, $direction)
{
	$status = (int) $status;

	if (strtolower((string) $direction) == 'out') {
		return 'fournisseur';
	}
	if (in_array($status, array(200, 201, 202, 203, 213), true)) {
		return 'pdp';
	}
	return 'client';
}

/**
 * Line/dot color for a lifecycle event in the chronogram.
 *
 * @param int    $status Lifecycle status code
 * @param string $flux   'fournisseur'|'pdp'|'client'
 * @return string CSS color
 */
function einvoicingLifecycleColor($status, $flux)
{
	$status = (int) $status;

	if (in_array($status, array(210, 213), true)) {
		return '#A32D2D'; // refused / rejected
	}
	if ($status === 207) {
		return '#854F0B'; // disputed
	}
	if ($status === 208) {
		return '#CC9900'; // suspended
	}
	if ($flux === 'fournisseur') {
		return '#185FA5';
	}
	if ($flux === 'pdp') {
		return '#888780';
	}
	return '#3B6D11';
}

/**
 * Whether the event's arrow should be drawn dashed (negative/exception outcomes).
 *
 * @param int $status Lifecycle status code
 * @return bool
 */
function einvoicingLifecycleDashed($status)
{
	return in_array((int) $status, array(207, 208, 210, 213), true);
}

/**
 * Swimlane column index an event's arrow starts from (0=Fournisseur, 1=PDP/PA, 2=Client).
 *
 * @param string $flux 'fournisseur'|'pdp'|'client'
 * @return int
 */
function einvoicingLifecycleSeqFrom($flux)
{
	if ($flux === 'fournisseur') {
		return 0;
	}
	if ($flux === 'client') {
		return 2;
	}
	return 1; // pdp events are rendered as the network acknowledging back to us
}

/**
 * Swimlane column index an event's arrow points to (0=Fournisseur, 1=PDP/PA, 2=Client).
 *
 * @param string $flux 'fournisseur'|'pdp'|'client'
 * @return int
 */
function einvoicingLifecycleSeqTo($flux)
{
	if ($flux === 'pdp') {
		return 0;
	}
	return 1;
}

/**
 * Human label for a lifecycle event: the provider's own message when it carries more context than the
 * bare status code, otherwise the module's canonical status label.
 *
 * @param EInvoicing $einvoicing EInvoicing instance (source of canonical status labels)
 * @param int        $status     Lifecycle status code
 * @param string     $override   Provider message (lc_status_message), if any
 * @return string
 */
function einvoicingLifecycleLabel($einvoicing, $status, $override = '')
{
	$override = trim((string) $override);
	if ($override !== '' && $override !== (string) $status) {
		return einvoicingLifecyclePlain($override);
	}
	return $einvoicing->getStatusLabel($status);
}

/**
 * $langs->trans() may return HTML entities (&eacute;, ...); decode to plain UTF-8 so the SVG's
 * htmlspecialchars() does not double-encode them.
 *
 * @param string $str Input string
 * @return string
 */
function einvoicingLifecyclePlain($str)
{
	return html_entity_decode((string) $str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Render the e-invoicing lifecycle as an inline SVG sequence diagram (3 swimlanes: Fournisseur / PDP-PA
 * / Client), one row per event with an arrow between the actors involved, plus a bracket grouping events
 * sharing the same timestamp (i.e. produced by the same synchronization call) and a legend.
 *
 * @param array<int,array{code:int,label:string,tooltip:string,date:string,time:string,flux:string,seq_from:int,seq_to:int,color:string,dashed:bool,group:int,flow_id:string}> $events Ordered events (oldest first)
 * @return string SVG markup, or '' if $events is empty
 */
function einvoicingRenderLifecycleSvg(array $events)
{
	global $langs;

	$n = count($events);
	if ($n === 0) {
		return '';
	}

	$row_h = 44;
	$top   = 44;
	$bot   = 40;
	$h     = $top + $n * $row_h + $bot;
	$cx    = array(0 => 288, 1 => 450, 2 => 610);

	$o  = '<svg width="100%" viewBox="-10 0 670 '.$h.'" style="display:block;margin:8px 0;max-width:670px;">';
	$o .= '<defs><marker id="einvLcA" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse">'
		. '<path d="M2 1L8 5L2 9" fill="none" stroke="context-stroke" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
		. '</marker></defs>';
	$o .= '<line x1="65" y1="'.($top - 4).'" x2="65" y2="'.($top + $n * $row_h).'" stroke="#d3d1c7" stroke-width="0.5"/>';

	$ac = array(
		array('label' => $langs->transnoentities('EInvActorSeller'),   'x' => 240, 'w' => 96, 'cx' => 288, 'fi' => '#E6F1FB', 'st' => '#B5D4F4', 'tc' => '#0C447C', 'lc' => '#185FA5'),
		array('label' => $langs->transnoentities('EInvActorPlatform'), 'x' => 407, 'w' => 86, 'cx' => 450, 'fi' => '#F1EFE8', 'st' => '#D3D1C7', 'tc' => '#444441', 'lc' => '#888780'),
		array('label' => $langs->transnoentities('EInvActorBuyer'),    'x' => 567, 'w' => 86, 'cx' => 610, 'fi' => '#EAF3DE', 'st' => '#C0DD97', 'tc' => '#27500A', 'lc' => '#3B6D11'),
	);
	foreach ($ac as $a) {
		$o .= '<rect x="'.$a['x'].'" y="8" width="'.$a['w'].'" height="26" rx="6" fill="'.$a['fi'].'" stroke="'.$a['st'].'" stroke-width="0.5"/>';
		$o .= '<text x="'.$a['cx'].'" y="21" text-anchor="middle" dominant-baseline="central" font-size="11" font-weight="500" fill="'.$a['tc'].'">'
			. htmlspecialchars($a['label'], ENT_QUOTES).'</text>';
		$o .= '<line x1="'.$a['cx'].'" y1="34" x2="'.$a['cx'].'" y2="'.($top + $n * $row_h).'" stroke="'.$a['lc'].'" stroke-width="0.5" stroke-dasharray="5 4"/>';
	}

	foreach ($events as $i => $e) {
		$y = $top + $i * $row_h + (int) ($row_h / 2);
		$c = $e['color'];

		// Date / heure
		$o .= '<text x="4" y="'.($y - 6).'" dominant-baseline="central" font-size="10" font-weight="500" fill="#444441">'.htmlspecialchars($e['date'], ENT_QUOTES).'</text>';
		$o .= '<text x="4" y="'.($y + 7).'" dominant-baseline="central" font-size="10" fill="#888780">'.htmlspecialchars($e['time'], ENT_QUOTES).'</text>';

		// Cercle
		$r = ($e['flux'] === 'fournisseur' || $e['flux'] === 'client') ? 7 : 6;
		$o .= '<circle cx="65" cy="'.$y.'" r="'.$r.'" fill="'.$c.'"/>';

		// Label (déjà tronqué par l'appelant) — tooltip si présent
		$label_text = htmlspecialchars($e['label'], ENT_QUOTES);
		if (!empty($e['tooltip'])) {
			$o .= '<g style="cursor:help;">'
				. '<title>'.htmlspecialchars($e['tooltip'], ENT_QUOTES).'</title>'
				. '<text x="77" y="'.$y.'" dominant-baseline="central" font-size="10" font-weight="500" fill="'.$c.'" text-decoration="underline dotted">'.$label_text.'</text>'
				. '</g>';
		} else {
			$o .= '<text x="77" y="'.$y.'" dominant-baseline="central" font-size="10" font-weight="500" fill="'.$c.'">'.$label_text.'</text>';
		}

		// Flèche entre acteurs
		$fx = $cx[$e['seq_from']];
		$tx = $cx[$e['seq_to']];
		if ($fx !== $tx) {
			$x1 = ($fx < $tx) ? $fx + 10 : $fx - 10;
			$x2 = ($fx < $tx) ? $tx - 10 : $tx + 10;
			$da = $e['dashed'] ? ' stroke-dasharray="4 3"' : '';
			$sw = ($e['flux'] === 'pdp' && $e['dashed']) ? '1' : '1.5';
			$o .= '<line x1="'.$x1.'" y1="'.$y.'" x2="'.$x2.'" y2="'.$y.'" stroke="'.$c.'" stroke-width="'.$sw.'"'.$da.' marker-end="url(#einvLcA)"/>';
			$lx = (int) (($x1 + $x2) / 2);
			$bg = ($e['flux'] === 'fournisseur') ? '#E6F1FB' : (($e['flux'] === 'client') ? '#EAF3DE' : '#F1EFE8');
			$o .= '<rect x="'.($lx - 44).'" y="'.($y - 12).'" width="88" height="16" rx="3" fill="'.$bg.'"/>';
			$o .= '<text x="'.$lx.'" y="'.($y - 4).'" text-anchor="middle" dominant-baseline="central" font-size="10" font-weight="500" fill="#2C2C2A">'.htmlspecialchars((string) $e['code'], ENT_QUOTES).'</text>';
		}
	}

	// Crochet de regroupement pour les events partageant le même horodatage (même appel de synchronisation)
	$groupMap = array();
	foreach ($events as $i => $e) {
		$groupMap[$e['group']][] = $i;
	}
	foreach ($groupMap as $indices) {
		if (count($indices) < 2) {
			continue;
		}
		sort($indices);
		$iFirst = $indices[0];
		$iLast  = $indices[count($indices) - 1];
		$yTop   = $top + $iFirst * $row_h + 6;
		$yBot   = $top + ($iLast + 1) * $row_h - 6;

		// Transaction reference (flow_id) shared by the grouped events, if any.
		$groupFlowId = '';
		foreach ($indices as $idx) {
			if (!empty($events[$idx]['flow_id'])) {
				$groupFlowId = $events[$idx]['flow_id'];
				break;
			}
		}

		$rect = '<rect x="-8" y="'.$yTop.'" width="4" height="'.($yBot - $yTop).'" rx="2" fill="#e8e6e0" stroke="#888780" stroke-width="0.75"/>';
		if ($groupFlowId !== '') {
			$o .= '<g style="cursor:help;">'
				. '<title>'.htmlspecialchars($langs->transnoentities('EInvTransactionReference').' : '.$groupFlowId, ENT_QUOTES).'</title>'
				. $rect
				. '</g>';
		} else {
			$o .= $rect;
		}
	}

	$ly = $top + $n * $row_h + 20;
	$lg = array(
		array('x' => 240, 's' => '#185FA5', 'l' => $langs->transnoentities('EInvActorSeller'),   't' => '#0C447C'),
		array('x' => 356, 's' => '#888780', 'l' => $langs->transnoentities('EInvActorPlatform'), 't' => '#444441'),
		array('x' => 458, 's' => '#3B6D11', 'l' => $langs->transnoentities('EInvActorBuyer'),    't' => '#27500A'),
	);
	foreach ($lg as $l) {
		$o .= '<line x1="'.$l['x'].'" y1="'.$ly.'" x2="'.($l['x'] + 26).'" y2="'.$ly.'" stroke="'.$l['s'].'" stroke-width="1.5" marker-end="url(#einvLcA)"/>';
		$o .= '<text x="'.($l['x'] + 32).'" y="'.$ly.'" dominant-baseline="central" font-size="10" fill="'.$l['t'].'">'.htmlspecialchars($l['l'], ENT_QUOTES).'</text>';
	}

	$o .= '</svg>';

	return $o;
}
