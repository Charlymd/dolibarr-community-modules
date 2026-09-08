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
 * \brief   Actor classification, labelling and chronogram (SVG sequence diagram) rendering for the
 *          e-invoicing lifecycle of an element (invoice).
 *
 * Reads the multi-provider llx_einvoicing_lifecycle_msg table, which stores a numeric XP Z12-012 status
 * code (EInvoicing::STATUS_*) plus a direction ('in'/'out'), and turns it into the three actors a
 * customer invoice tracking view cares about: 'fournisseur' (Dolibarr/seller side, us), 'pdp' (network /
 * Access Point layer) and 'client' (buyer side).
 *
 * The chronogram is colored through CSS classes resolved by an embedded stylesheet (einvoicingLifecycleCss()),
 * not inline hex fills, so it stays readable under both a light and a dark color scheme.
 */

/**
 * Swimlane (actor) a lifecycle event belongs to: 'fournisseur' (Dolibarr/seller side, us), 'pdp'
 * (network / Access Point layer) or 'client' (buyer side).
 *
 * Direction gives the primary signal (out = emitted by us, in = received back). For an 'in' event, the
 * XP Z12-012 status code refines it between the network acknowledging our submission and the buyer's own
 * processing, using EInvoicing::STATUS_* so the classification cannot drift from the module's status map.
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

	$networkStatuses = array(
		EInvoicing::STATUS_DEPOSITED,
		EInvoicing::STATUS_ISSUED,
		EInvoicing::STATUS_RECEIVED,
		EInvoicing::STATUS_AVAILABLE,
		EInvoicing::STATUS_REJECTED,
	);
	if (in_array($status, $networkStatuses, true)) {
		return 'pdp';
	}

	return 'client';
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
 * $langs->trans() may return HTML entities (&eacute;, ...); decode to plain UTF-8 so callers that embed
 * the result in a context with its own escaping (e.g. an SVG's htmlspecialchars()) do not double-encode
 * them.
 *
 * @param string $str Input string
 * @return string
 */
function einvoicingLifecyclePlain($str)
{
	return html_entity_decode((string) $str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * CSS class carrying the color of a lifecycle event in the chronogram: a status-driven exception color
 * (refused/rejected, disputed, suspended) takes priority over the actor's default color, matched against
 * EInvoicing::STATUS_* so it cannot drift from the module's status map.
 *
 * @param int    $status Lifecycle status code (EInvoicing::STATUS_*)
 * @param string $flux   'fournisseur'|'pdp'|'client'
 * @return string CSS class suffix: 'refused'|'disputed'|'suspended'|'fournisseur'|'pdp'|'client'
 */
function einvoicingLifecycleColorClass($status, $flux)
{
	$status = (int) $status;

	if (in_array($status, array(EInvoicing::STATUS_REFUSED, EInvoicing::STATUS_REJECTED), true)) {
		return 'refused';
	}
	if ($status === EInvoicing::STATUS_DISPUTED) {
		return 'disputed';
	}
	if ($status === EInvoicing::STATUS_SUSPENDED) {
		return 'suspended';
	}

	return $flux;
}

/**
 * Whether the event's arrow should be drawn dashed (negative/exception outcomes).
 *
 * @param int $status Lifecycle status code (EInvoicing::STATUS_*)
 * @return bool
 */
function einvoicingLifecycleDashed($status)
{
	$exceptionStatuses = array(
		EInvoicing::STATUS_DISPUTED,
		EInvoicing::STATUS_SUSPENDED,
		EInvoicing::STATUS_REFUSED,
		EInvoicing::STATUS_REJECTED,
	);
	return in_array((int) $status, $exceptionStatuses, true);
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
 * Embedded stylesheet resolving the einvlc-c-* classes used by the chronogram to actual colors: a light
 * palette by default, swapped for a dark-friendly one under `prefers-color-scheme: dark`. The module has
 * no other established dark-theme hook to key off, so this follows the OS/browser color scheme like any
 * self-contained inline SVG would.
 *
 * @return string <style> element
 */
function einvoicingLifecycleCss()
{
	return '<style>'
		. '.einvlc{'
		. '--einvlc-fournisseur:#185FA5;--einvlc-fournisseur-bg:#E6F1FB;--einvlc-fournisseur-border:#B5D4F4;--einvlc-fournisseur-text:#0C447C;'
		. '--einvlc-pdp:#767468;--einvlc-pdp-bg:#F1EFE8;--einvlc-pdp-border:#D3D1C7;--einvlc-pdp-text:#444441;'
		. '--einvlc-client:#3B6D11;--einvlc-client-bg:#EAF3DE;--einvlc-client-border:#C0DD97;--einvlc-client-text:#27500A;'
		. '--einvlc-refused:#A32D2D;--einvlc-disputed:#854F0B;--einvlc-suspended:#B8860B;'
		. '--einvlc-axis:#d3d1c7;--einvlc-muted:#767468;--einvlc-ink:#2C2C2A;'
		. '--einvlc-group-fill:#e8e6e0;--einvlc-group-stroke:#888780;'
		. '}'
		. '@media (prefers-color-scheme:dark){.einvlc{'
		. '--einvlc-fournisseur:#6FA8DC;--einvlc-fournisseur-bg:#182534;--einvlc-fournisseur-border:#2E4A63;--einvlc-fournisseur-text:#9CC6EA;'
		. '--einvlc-pdp:#ABA89E;--einvlc-pdp-bg:#26241E;--einvlc-pdp-border:#423F37;--einvlc-pdp-text:#C9C6BC;'
		. '--einvlc-client:#8FC454;--einvlc-client-bg:#1E2B15;--einvlc-client-border:#3C5527;--einvlc-client-text:#AEDD82;'
		. '--einvlc-refused:#E28080;--einvlc-disputed:#D9A75C;--einvlc-suspended:#E0C24C;'
		. '--einvlc-axis:#48453D;--einvlc-muted:#ABA89E;--einvlc-ink:#E6E4E0;'
		. '--einvlc-group-fill:#2E2B24;--einvlc-group-stroke:#6B6860;'
		. '}}'
		. '.einvlc text{font-family:inherit;}'
		. '.einvlc-c-fournisseur{fill:var(--einvlc-fournisseur);stroke:var(--einvlc-fournisseur);}'
		. '.einvlc-c-pdp{fill:var(--einvlc-pdp);stroke:var(--einvlc-pdp);}'
		. '.einvlc-c-client{fill:var(--einvlc-client);stroke:var(--einvlc-client);}'
		. '.einvlc-c-refused{fill:var(--einvlc-refused);stroke:var(--einvlc-refused);}'
		. '.einvlc-c-disputed{fill:var(--einvlc-disputed);stroke:var(--einvlc-disputed);}'
		. '.einvlc-c-suspended{fill:var(--einvlc-suspended);stroke:var(--einvlc-suspended);}'
		. '.einvlc-bg-fournisseur{fill:var(--einvlc-fournisseur-bg);stroke:var(--einvlc-fournisseur-border);}'
		. '.einvlc-bg-pdp{fill:var(--einvlc-pdp-bg);stroke:var(--einvlc-pdp-border);}'
		. '.einvlc-bg-client{fill:var(--einvlc-client-bg);stroke:var(--einvlc-client-border);}'
		. '.einvlc-t-fournisseur{fill:var(--einvlc-fournisseur-text);}'
		. '.einvlc-t-pdp{fill:var(--einvlc-pdp-text);}'
		. '.einvlc-t-client{fill:var(--einvlc-client-text);}'
		. '.einvlc-axis{stroke:var(--einvlc-axis);}'
		. '.einvlc-lane{stroke-dasharray:5 4;}'
		. '.einvlc-date{fill:var(--einvlc-ink);}'
		. '.einvlc-time{fill:var(--einvlc-muted);}'
		. '.einvlc-code{fill:var(--einvlc-ink);}'
		. '.einvlc-group{fill:var(--einvlc-group-fill);stroke:var(--einvlc-group-stroke);}'
		. '</style>';
}

/**
 * Render the e-invoicing lifecycle as an inline SVG sequence diagram (3 swimlanes: Fournisseur / PDP-PA
 * / Client), one row per event with an arrow between the actors involved, plus a bracket grouping events
 * sharing the same timestamp (i.e. produced by the same synchronization call) and a legend.
 *
 * Fluid width: the <svg> has no fixed pixel width, only a viewBox, so it scales down to a narrow tab panel
 * and up to the full width of a wide one instead of being capped at a fixed size.
 *
 * @param array<int,array{code:int,label:string,tooltip:string,date:string,time:string,flux:string,seq_from:int,seq_to:int,color_class:string,dashed:bool,group:int,flow_id:string}> $events Ordered events (oldest first)
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

	$o  = '<svg class="einvlc" width="100%" viewBox="-10 0 670 '.$h.'" style="display:block;margin:8px 0;">';
	$o .= einvoicingLifecycleCss();
	$o .= '<defs><marker id="einvLcA" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse">'
		. '<path d="M2 1L8 5L2 9" fill="none" stroke="context-stroke" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
		. '</marker></defs>';
	$o .= '<line class="einvlc-axis" x1="65" y1="'.($top - 4).'" x2="65" y2="'.($top + $n * $row_h).'" stroke-width="0.5"/>';

	$ac = array(
		array('label' => $langs->transnoentities('EInvActorSeller'),   'cls' => 'fournisseur', 'x' => 240, 'w' => 96, 'cx' => 288),
		array('label' => $langs->transnoentities('EInvActorPlatform'), 'cls' => 'pdp',         'x' => 407, 'w' => 86, 'cx' => 450),
		array('label' => $langs->transnoentities('EInvActorBuyer'),    'cls' => 'client',       'x' => 567, 'w' => 86, 'cx' => 610),
	);
	foreach ($ac as $a) {
		$o .= '<rect class="einvlc-bg-'.$a['cls'].'" x="'.$a['x'].'" y="8" width="'.$a['w'].'" height="26" rx="6" stroke-width="0.5"/>';
		$o .= '<text class="einvlc-t-'.$a['cls'].'" x="'.$a['cx'].'" y="21" text-anchor="middle" dominant-baseline="central" font-size="11" font-weight="500">'
			. htmlspecialchars($a['label'], ENT_QUOTES).'</text>';
		$o .= '<line class="einvlc-c-'.$a['cls'].' einvlc-lane" x1="'.$a['cx'].'" y1="34" x2="'.$a['cx'].'" y2="'.($top + $n * $row_h).'" stroke-width="0.5"/>';
	}

	foreach ($events as $i => $e) {
		$y = $top + $i * $row_h + (int) ($row_h / 2);
		$cls = $e['color_class'];

		// Date / heure
		$o .= '<text class="einvlc-date" x="4" y="'.($y - 6).'" dominant-baseline="central" font-size="10" font-weight="500">'.htmlspecialchars($e['date'], ENT_QUOTES).'</text>';
		$o .= '<text class="einvlc-time" x="4" y="'.($y + 7).'" dominant-baseline="central" font-size="10">'.htmlspecialchars($e['time'], ENT_QUOTES).'</text>';

		// Cercle
		$r = ($e['flux'] === 'fournisseur' || $e['flux'] === 'client') ? 7 : 6;
		$o .= '<circle class="einvlc-c-'.$cls.'" cx="65" cy="'.$y.'" r="'.$r.'"/>';

		// Label (déjà tronqué par l'appelant) — tooltip si présent
		$label_text = htmlspecialchars($e['label'], ENT_QUOTES);
		if (!empty($e['tooltip'])) {
			$o .= '<g style="cursor:help;">'
				. '<title>'.htmlspecialchars($e['tooltip'], ENT_QUOTES).'</title>'
				. '<text class="einvlc-c-'.$cls.'" x="77" y="'.$y.'" dominant-baseline="central" font-size="10" font-weight="500" text-decoration="underline dotted">'.$label_text.'</text>'
				. '</g>';
		} else {
			$o .= '<text class="einvlc-c-'.$cls.'" x="77" y="'.$y.'" dominant-baseline="central" font-size="10" font-weight="500">'.$label_text.'</text>';
		}

		// Flèche entre acteurs
		$fx = $cx[$e['seq_from']];
		$tx = $cx[$e['seq_to']];
		if ($fx !== $tx) {
			$x1 = ($fx < $tx) ? $fx + 10 : $fx - 10;
			$x2 = ($fx < $tx) ? $tx - 10 : $tx + 10;
			$da = $e['dashed'] ? ' stroke-dasharray="4 3"' : '';
			$sw = ($e['flux'] === 'pdp' && $e['dashed']) ? '1' : '1.5';
			$o .= '<line class="einvlc-c-'.$cls.'" x1="'.$x1.'" y1="'.$y.'" x2="'.$x2.'" y2="'.$y.'" stroke-width="'.$sw.'"'.$da.' marker-end="url(#einvLcA)"/>';
			$lx = (int) (($x1 + $x2) / 2);
			$bg = ($e['flux'] === 'fournisseur') ? 'fournisseur' : (($e['flux'] === 'client') ? 'client' : 'pdp');
			$o .= '<rect class="einvlc-bg-'.$bg.'" x="'.($lx - 44).'" y="'.($y - 12).'" width="88" height="16" rx="3" stroke="none"/>';
			$o .= '<text class="einvlc-code" x="'.$lx.'" y="'.($y - 4).'" text-anchor="middle" dominant-baseline="central" font-size="10" font-weight="500">'.htmlspecialchars((string) $e['code'], ENT_QUOTES).'</text>';
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

		$rect = '<rect class="einvlc-group" x="-8" y="'.$yTop.'" width="4" height="'.($yBot - $yTop).'" rx="2" stroke-width="0.75"/>';
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
		array('x' => 240, 'cls' => 'fournisseur', 'l' => $langs->transnoentities('EInvActorSeller')),
		array('x' => 356, 'cls' => 'pdp',         'l' => $langs->transnoentities('EInvActorPlatform')),
		array('x' => 458, 'cls' => 'client',       'l' => $langs->transnoentities('EInvActorBuyer')),
	);
	foreach ($lg as $l) {
		$o .= '<line class="einvlc-c-'.$l['cls'].'" x1="'.$l['x'].'" y1="'.$ly.'" x2="'.($l['x'] + 26).'" y2="'.$ly.'" stroke-width="1.5" marker-end="url(#einvLcA)"/>';
		$o .= '<text class="einvlc-t-'.$l['cls'].'" x="'.($l['x'] + 32).'" y="'.$ly.'" dominant-baseline="central" font-size="10">'.htmlspecialchars($l['l'], ENT_QUOTES).'</text>';
	}

	$o .= '</svg>';

	return $o;
}
