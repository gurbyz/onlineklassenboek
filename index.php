<? require_once("include/init.php");

if (isset($_SESSION['ppl_id'])  && (!isset($_GET) || !isset($_GET['lock_by']))) {
	check_login();
} else {
// laat zo nodig loginscherm zien
if (!isset($_SESSION['ppl_id']) || $load_time - $_SESSION['last_load_time'] > 60*$_SESSION['timeout']) {
	include("include/login.php");
} else $_SESSION['last_load_time'] = $load_time;
}

// check if url variables are set, otherwise set reasonable defaults and reload
$reload = 0;

$http_get = "";

$week_options = gen_week_select(isset($_GET['week'])?$_GET['week']:NULL, 1, $week);

$http_get['week'] = "$week";

$grp2vak_select = sprint_grp2vak_select(isset($_GET['grp2vak_id'])?$_GET['grp2vak_id']:NULL, " onchange=\"document.select.doelgroep[1].checked = true; document.select.submit();\"", $grp2vak_id, 0);

if ($grp2vak_select) {
	if (isset($_GET['doelgroep']) && $_GET['doelgroep'] == "lesgroep") {
		$doelgroep = "lesgroep";
	} else if (isset($_GET['doelgroep']) && $_GET['doelgroep'] == 'leerling') {
		$doelgroep = "leerling";
	} else {
		$doelgroep = "zelf";
	}
	if ($_SESSION['type'] == 'ouder') $lln_options = sprint_leerling_select($_GET['lln'], " onchange=\"document.select.submit();\"", $lln_id, 0, $grp2vak_id);
	else $lln_options = sprint_leerling_select(isset($_GET['lln'])?$_GET['lln']:NULL, " onchange=\"document.select.doelgroep[2].checked = true; document.select.submit();\"", $lln_id, 0, $grp2vak_id);

	if ($lln_options) {
		$http_get['doelgroep'] = $doelgroep;
		$http_get['grp2vak_id'] = $grp2vak_id;
		$http_get['lln'] = $lln_id;
	} else {
		if ($doelgroep == 'leerling') {
			$reload = 1;
		}
		$doelgroep = 'lesgroep';
		$http_get['grp2vak_id'] = $grp2vak_id;
		$http_get['doelgroep'] = 'lesgroep';
	}
} else {
	if ($_GET['doelgroep'] != 'zelf') {
		$reload = 1;
	}
	$doelgroep = 'zelf';
	$http_get['doelgroep'] = 'zelf';
}
if ($_SESSION['type'] == 'ouder' && $doelgroep != 'leerling' && $lln_options) {
	$doelgroep = 'leerling';
	$http_get['doelgroep'] = 'leerling';
	$reload = 1;
}

if ($reload == 1) {
	$send = "?";
	foreach ($http_get as $key => $value) {
		$send .= "$key=$value&";
	}
	$send = substr($send, 0, strlen($send) - 1);
	header("Location: index.php$send");
	exit;
}

if ($_SESSION['type'] == 'ouder') {
	$doelgroep = 'leerling';
	if (!$lln_options) {
		header('Location: ouder_auth_request.php');
		exit;
	}
}

mysql_query_safe("SET SESSION group_concat_max_len = 65536");

$no_issues = sprint_singular(<<<EOQ
SELECT COUNT(*) FROM ppl2agenda
	JOIN agenda USING (agenda_id)
	JOIN notities USING (notitie_id)
	WHERE ppl_id = {$_SESSION['ppl_id']}
	AND text IS NULL
EOQ
);
$common = "&doelgroep=$doelgroep&view=week&week=$week&grp2vak_id=$grp2vak_id&lln=$lln_id";

if ($doelgroep == 'leerling') {
mysql_query_safe("set @i = 0;");
$this_issues = mysql_query_safe(<<<EOQ
SELECT @i := @i + 1 as row_number,
	CONCAT(agenda.week, CASE agenda.dag WHEN 1 THEN 'ma' WHEN 2 THEN 'di' WHEN 3 THEN 'wo'
		WHEN 4 THEN 'do' ELSE 'vr' END, agenda.lesuur, ' ', IFNULL(vak.afkorting, ''), ': ', parent.text,
		' <span class="tag">[', tag, ']</span> <a style="text-decoration: none" href="vvv_zelf.php?notitie_id=',
                notities.notitie_id, '$common&dag=%%d&lesuur=%%d">sluiten</a>') issue, notities.notitie_id FROM agenda 
JOIN ppl2agenda AS lln2agenda USING (agenda_id)
JOIN ppl2agenda AS doc2agenda USING (agenda_id)
JOIN notities USING (notitie_id)
JOIN tags2notities USING (notitie_id)
JOIN tags USING (tag_id)
JOIN notities AS parent ON parent.notitie_id = notities.parent_id
JOIN agenda AS p_agenda ON p_agenda.notitie_id = parent.notitie_id
LEFT JOIN grp2vak2agenda ON grp2vak2agenda.agenda_id = p_agenda.agenda_id
LEFT JOIN grp2vak USING (grp2vak_id)
LEFT JOIN vak USING (vak_id)
WHERE lln2agenda.ppl_id = '$lln_id'
AND doc2agenda.ppl_id = {$_SESSION['ppl_id']}
AND notities.text IS NULL
EOQ
);
while ($row = mysql_fetch_row($this_issues)) {
	$issues_friendly .= '<span class="tag">'.$row[0].'</span> '.$row[1].'<br>';
	$issues_links[$row[0] - 1] = ' <span class="tag"><a style="text-decoration: none" href="cont_issue.php?notitie_id='.$row[2].$common.'&dag=%s">'.$row[0].'</a></span>';
}
$lln_naam = sprint_singular("SELECT KB_NAAM(naam0, naam1, naam2) FROM ppl WHERE ppl_id = '$lln_id'");
}
$plus_end = "$common\">+</a>";

$grp_id = NULL;

switch ($doelgroep) {

	case 'zelf';
		$plus_start =<<<EOL
<a style="text-decoration: none" href="new_zelf.php?dag=
EOL;
		$inner_query =<<<EOQ
SELECT agenda.dag, agenda.lesuur, CONCAT(KB_LGRP(grp.naam, vak.afkorting), ': ') target,
	notities.text, notities.notitie_id, NULL action_name, NULL action_id, 
	1 dont, tags2notities.tag_id, 1 grp, 1 edit
FROM notities
JOIN agenda USING (notitie_id)
JOIN grp2vak2agenda USING (agenda_id)
JOIN doc2grp2vak USING (grp2vak_id)
JOIN grp2vak USING (grp2vak_id)
JOIN grp USING (grp_id)
LEFT JOIN vak USING (vak_id)
LEFT JOIN tags2notities USING (notitie_id)
WHERE doc2grp2vak.ppl_id = {$_SESSION['ppl_id']}
AND agenda.week = '$week' AND agenda.schooljaar = '$schooljaar'
GROUP BY notities.notitie_id, tags2notities.tag_id, action_id
UNION
SELECT agenda.dag, agenda.lesuur,
	CONCAT(KB_LGRP(grp.naam, vak.afkorting), ': ') target,
	IF(cijfer IS NULL, notities.text, CONCAT(notities.text, ' <span style="color: ', IF(cijfer < 5.5, 'red', 'green'), ';">(', cijfer, ')</span>')) text,
	notities.notitie_id, NULL action_name, NULL action_id, NULL dont, tags2notities.tag_id, 1 grp, 0 edit
FROM notities
JOIN agenda USING (notitie_id)
JOIN grp2vak2agenda USING (agenda_id)
JOIN grp2vak USING (grp2vak_id)
JOIN grp USING (grp_id)
LEFT JOIN vak USING (vak_id)
LEFT JOIN tags2notities USING (notitie_id)
JOIN ppl2grp USING (grp_id)
LEFT JOIN doc2grp2vak ON doc2grp2vak.grp2vak_id = grp2vak.grp2vak_id AND doc2grp2vak.ppl_id = {$_SESSION['ppl_id']}
LEFT JOIN cijfers ON notities.notitie_id = cijfers.notitie_id AND cijfers.ppl_id = '{$_SESSION['ppl_id']}'
WHERE ppl2grp.ppl_id = {$_SESSION['ppl_id']}
AND agenda.week = '$week' AND agenda.schooljaar = '$schooljaar'
AND doc2grp2vak.ppl_id IS NULL
GROUP BY notitie_id, tag_id
UNION
SELECT bla0.dag, bla0.lesuur,
target, bla0.text, bla0.notitie_id, action_name, action_id,
GROUP_CONCAT(CONCAT(bla.agenda_id, '-', tags2children.tag_id)) dont, tags2notities.tag_id, 0 grp, edit
FROM ( 
	SELECT notitie_id, text, agenda.dag, agenda.lesuur, agenda.agenda_id,
		CONCAT(GROUP_CONCAT(IF(corro.type != 'leerling', corro.login,
				KB_NAAM(corro.naam0, corro.naam1, corro.naam2))
			COLLATE utf8_unicode_ci), ': ') target, ppl2agenda.allow_edit edit
	FROM notities
	JOIN agenda USING (notitie_id)
	JOIN ppl2agenda USING (agenda_id)
	LEFT JOIN ppl2agenda AS corro2agenda ON corro2agenda.agenda_id = agenda.agenda_id
	LEFT JOIN ppl AS corro ON corro2agenda.ppl_id = corro.ppl_id AND corro.ppl_id != {$_SESSION['ppl_id']}
	WHERE ppl2agenda.ppl_id = {$_SESSION['ppl_id']}
	AND agenda.week = '$week' AND agenda.schooljaar = '$schooljaar'
	GROUP BY notitie_id
) AS bla0
LEFT JOIN tags2notities USING (notitie_id)
LEFT JOIN tags2actions USING (tag_id)
LEFT JOIN actions USING (action_id)
LEFT JOIN ppl2agenda AS targets ON bla0.agenda_id = targets.agenda_id AND targets.allow_edit = 0
LEFT JOIN notities AS children ON bla0.notitie_id = children.parent_id
LEFT JOIN agenda AS c_agenda ON children.notitie_id = c_agenda.notitie_id
LEFT JOIN tags2notities AS tags2children ON children.notitie_id = tags2children.notitie_id AND actions.new_tag_id = tags2children.tag_id
LEFT JOIN ppl2agenda AS bla ON c_agenda.agenda_id = bla.agenda_id AND bla.ppl_id = targets.ppl_id
GROUP BY bla0.notitie_id, tags2notities.tag_id, action_id
EOQ;
		break;

	case 'leerling';
		$plus_start =<<<EOL
<a style="text-decoration: none" href="new_leerling.php?dag=
EOL;
		$inner_query =<<<EOQ
SELECT agenda.dag, agenda.lesuur, CONCAT(KB_LGRP(grp.naam, vak.afkorting), ': ') target,
	notities.text, notities.notitie_id, action_name, action_id, 
	GROUP_CONCAT(CONCAT(bla.agenda_id, '-', tags2children.tag_id)) dont,
	tags2notities.tag_id, 1 grp, IF(auth.ppl_id, 1, 0) edit
FROM notities
JOIN agenda USING (notitie_id)
JOIN grp2vak2agenda USING (agenda_id)
JOIN doc2grp2vak USING (grp2vak_id)
JOIN grp2vak USING (grp2vak_id)
JOIN grp USING (grp_id)
LEFT JOIN vak USING (vak_id)
LEFT JOIN tags2notities USING (notitie_id)
LEFT JOIN tags2actions USING (tag_id)
LEFT JOIN actions USING (action_id)
LEFT JOIN notities AS children ON notities.notitie_id = children.parent_id
LEFT JOIN agenda AS c_agenda ON children.notitie_id = c_agenda.notitie_id
LEFT JOIN tags2notities AS tags2children ON children.notitie_id = tags2children.notitie_id AND actions.new_tag_id = tags2children.tag_id
LEFT JOIN ppl2agenda AS bla ON c_agenda.agenda_id = bla.agenda_id AND bla.ppl_id = '$lln_id'
LEFT JOIN doc2grp2vak AS auth ON auth.grp2vak_id = grp2vak.grp2vak_id AND auth.ppl_id = {$_SESSION['ppl_id']}
WHERE doc2grp2vak.ppl_id = '$lln_id'
AND agenda.week = '$week' AND agenda.schooljaar = '$schooljaar'
GROUP BY notities.notitie_id, tags2notities.tag_id, action_id
UNION
SELECT agenda.dag, agenda.lesuur,
	CONCAT(IF(vak.afkorting IS NULL, grp.naam, vak.afkorting), ': ') target,
	IF(cijfer IS NULL, notities.text, CONCAT(notities.text, ' <span style="color: ', IF(cijfer < 5.5, 'red', 'green'), ';">(', cijfer, ')</span>')) text,
	notities.notitie_id, action_name, action_id, GROUP_CONCAT(CONCAT(bla.agenda_id, '-', tags2children.tag_id)) dont, tags2notities.tag_id, 1 grp, IF(auth.ppl_id, 1, 0) edit
FROM notities
JOIN agenda USING (notitie_id)
JOIN grp2vak2agenda USING (agenda_id)
JOIN grp2vak USING (grp2vak_id)
JOIN grp USING (grp_id)
LEFT JOIN vak USING (vak_id)
LEFT JOIN tags2notities USING (notitie_id)
JOIN ppl2grp USING (grp_id)
LEFT JOIN doc2grp2vak ON doc2grp2vak.grp2vak_id = grp2vak.grp2vak_id AND doc2grp2vak.ppl_id = '$lln_id'
LEFT JOIN doc2grp2vak AS auth ON auth.grp2vak_id = grp2vak.grp2vak_id AND auth.ppl_id = {$_SESSION['ppl_id']}
LEFT JOIN tags2actions USING (tag_id)
LEFT JOIN actions USING (action_id)
LEFT JOIN notities AS children ON notities.notitie_id = children.parent_id
LEFT JOIN agenda AS c_agenda ON children.notitie_id = c_agenda.notitie_id
LEFT JOIN tags2notities AS tags2children ON children.notitie_id = tags2children.notitie_id AND actions.new_tag_id = tags2children.tag_id
LEFT JOIN ppl2agenda AS bla ON c_agenda.agenda_id = bla.agenda_id AND bla.ppl_id = '$lln_id'
LEFT JOIN cijfers ON notities.notitie_id = cijfers.notitie_id AND cijfers.ppl_id = '$lln_id'
WHERE ppl2grp.ppl_id = '$lln_id'
AND agenda.week = '$week' AND agenda.schooljaar = '$schooljaar'
AND doc2grp2vak.ppl_id IS NULL
GROUP BY notitie_id, tag_id, action_id
UNION
SELECT bla0.dag, bla0.lesuur,
target, bla0.text, bla0.notitie_id, action_name, action_id,
GROUP_CONCAT(CONCAT(bla.agenda_id, '-', tags2children.tag_id)) dont, tags2notities.tag_id, 0 grp, edit
FROM ( 
	SELECT notitie_id, text, agenda.dag, agenda.lesuur, agenda.agenda_id,
		CONCAT(GROUP_CONCAT(IF(corro.type != 'leerling', corro.login,
				KB_NAAM(corro.naam0, corro.naam1, corro.naam2))
			COLLATE utf8_unicode_ci), ': ') target, auth.allow_edit edit
	FROM notities
	JOIN agenda USING (notitie_id)
	JOIN ppl2agenda USING (agenda_id)
	LEFT JOIN ppl2agenda AS corro2agenda ON corro2agenda.agenda_id = agenda.agenda_id
	LEFT JOIN ppl AS corro ON corro2agenda.ppl_id = corro.ppl_id AND corro.ppl_id != '$lln_id'
	LEFT JOIN ppl2agenda AS auth ON agenda.agenda_id = auth.agenda_id AND auth.ppl_id = {$_SESSION['ppl_id']}
	WHERE ppl2agenda.ppl_id = '$lln_id'
	AND agenda.week = '$week' AND agenda.schooljaar = '$schooljaar'
	GROUP BY notitie_id
) AS bla0
LEFT JOIN tags2notities USING (notitie_id)
LEFT JOIN tags2actions USING (tag_id)
LEFT JOIN actions USING (action_id)
LEFT JOIN ppl2agenda AS targets ON bla0.agenda_id = targets.agenda_id AND targets.allow_edit = 0
LEFT JOIN notities AS children ON bla0.notitie_id = children.parent_id
LEFT JOIN agenda AS c_agenda ON children.notitie_id = c_agenda.notitie_id
LEFT JOIN tags2notities AS tags2children ON children.notitie_id = tags2children.notitie_id AND actions.new_tag_id = tags2children.tag_id
LEFT JOIN ppl2agenda AS bla ON c_agenda.agenda_id = bla.agenda_id AND bla.ppl_id = targets.ppl_id
GROUP BY bla0.notitie_id, tags2notities.tag_id, action_id
EOQ;
		break;

	case 'lesgroep';
		$grp_id = sprint_singular("SELECT grp_id FROM grp2vak WHERE grp2vak_id = $grp2vak_id");
		$plus_start =<<<EOL
<a style="text-decoration: none" href="new.php?dag=
EOL;
		$inner_query =<<<EOQ
SELECT bla0.dag, bla0.lesuur,
target, bla0.text, bla0.notitie_id, action_name, action_id,
GROUP_CONCAT(CONCAT(bla.agenda_id, '-', tags2children.tag_id)) dont, tags2notities.tag_id, 0 grp, edit
FROM ( 
	SELECT notitie_id, text, agenda.dag, agenda.lesuur, agenda.agenda_id,
		CONCAT(GROUP_CONCAT(IF(corro.type != 'leerling', corro.login,
				KB_NAAM(corro.naam0, corro.naam1, corro.naam2))
			COLLATE utf8_unicode_ci), ': ') target, auth.allow_edit edit
	FROM notities
	JOIN agenda USING (notitie_id)
	JOIN ppl2agenda USING (agenda_id)
	JOIN ppl2grp USING (ppl_id)
	JOIN grp2vak USING (grp_id)
	LEFT JOIN ppl2agenda AS corro2agenda ON corro2agenda.agenda_id = agenda.agenda_id
	LEFT JOIN ppl AS corro ON corro2agenda.ppl_id = corro.ppl_id AND corro.ppl_id != '{$_SESSION['ppl_id']}'
	JOIN ppl2agenda AS auth ON agenda.agenda_id = auth.agenda_id AND auth.ppl_id = '{$_SESSION['ppl_id']}'
	WHERE grp2vak_id = '$grp2vak_id'
	AND agenda.week = '$week' AND agenda.schooljaar = '$schooljaar'
	GROUP BY notitie_id
) AS bla0
LEFT JOIN tags2notities USING (notitie_id)
LEFT JOIN tags2actions USING (tag_id)
LEFT JOIN actions USING (action_id)
LEFT JOIN ppl2agenda AS targets ON bla0.agenda_id = targets.agenda_id AND targets.allow_edit = 0
LEFT JOIN notities AS children ON bla0.notitie_id = children.parent_id
LEFT JOIN agenda AS c_agenda ON children.notitie_id = c_agenda.notitie_id
LEFT JOIN tags2notities AS tags2children ON children.notitie_id = tags2children.notitie_id AND actions.new_tag_id = tags2children.tag_id
LEFT JOIN ppl2agenda AS bla ON c_agenda.agenda_id = bla.agenda_id AND bla.ppl_id = targets.ppl_id
GROUP BY bla0.notitie_id, tags2notities.tag_id, action_id
UNION
SELECT dag, lesuur, CONCAT(IF(vak.afkorting IS NULL, grp.naam, vak.afkorting), ': ') target, text, notitie_id, NULL action_name, NULL action_id, 1 dont, tags2notities.tag_id, 1 grp, IF(doc2grp2vak.ppl_id IS NOT NULL, 1, 0)  edit
FROM agenda
JOIN notities USING (notitie_id)
JOIN grp2vak2agenda USING (agenda_id)
JOIN grp2vak USING (grp2vak_id)
LEFT JOIN tags2notities USING (notitie_id)
JOIN grp USING (grp_id)
LEFT JOIN vak USING (vak_id)
LEFT JOIN doc2grp2vak ON grp2vak.grp2vak_id = doc2grp2vak.grp2vak_id AND doc2grp2vak.ppl_id = '{$_SESSION['ppl_id']}'
WHERE week = '$week' AND agenda.schooljaar = '$schooljaar' AND grp_id = ( SELECT grp_id FROM grp2vak WHERE grp2vak_id = '$grp2vak_id' )
UNION
SELECT dag, lesuur, CONCAT(KB_LGRP(grp.naam, vak.afkorting), ': ') target, text, notitie_id, NULL action_name, NULL action_id, 1 dont, tags2notities.tag_id, 1 grp, IF(auth.ppl_id IS NOT NULL, 1, 0)  edit
FROM agenda
JOIN notities USING (notitie_id)
JOIN grp2vak2agenda USING (agenda_id)
JOIN grp2vak USING (grp2vak_id)
LEFT JOIN doc2grp2vak AS auth ON grp2vak.grp2vak_id = auth.grp2vak_id AND auth.ppl_id = '{$_SESSION['ppl_id']}'
JOIN grp USING (grp_id)
LEFT JOIN vak USING (vak_id)
LEFT JOIN tags2notities USING (notitie_id)
WHERE week = '$week' AND agenda.schooljaar = '$schooljaar'
AND grp_id = ANY (
	SELECT DISTINCT grp2.grp_id
	FROM grp
	JOIN ppl2grp USING (grp_id)
	JOIN ppl2grp AS ppl2equiv ON ppl2equiv.ppl_id = ppl2grp.ppl_id
	JOIN grp AS grp2 ON ppl2equiv.grp_id = grp2.grp_id AND grp.schooljaar = grp2.schooljaar
	WHERE grp.grp_id != grp2.grp_id AND grp.grp_id = ( SELECT grp_id FROM grp2vak WHERE grp2vak_id = '$grp2vak_id' )
	AND grp2.grp_type_id = ( SELECT grp_type_id FROM grp_types WHERE grp_type_naam = 'lesgroep' )
)
EOQ;
		break;
}

$year_lasthalf = substr($schooljaar_long, 5);
$year_firsthalf = substr($schooljaar_long, 0, 4);

$testresult = mysql_query_safe(<<<EOT
SELECT dag, lesuur, GROUP_CONCAT(text ORDER BY grp DESC, notitie_id SEPARATOR '\n') text
FROM (
	SELECT dag, lesuur, CONCAT(
		'<div class="',
		IF(grp, 'grp', 'pers'), '">',
		IFNULL(target, ''),
		IFNULL(bla3.text, ''),
		IFNULL(GROUP_CONCAT(tags SEPARATOR ''), ''),
		IF(edit, CONCAT(
			'\n',
			KB_LINK(
				IF(grp, 'vvv.php', 'vvv_zelf.php'),
				notitie_id, dag, lesuur, '$common', 'V')), ''),
		'</div>') text, notitie_id, 1 edit, grp
	FROM (
		SELECT dag, lesuur, target, text, edit, IFNULL(CONCAT(
			'\n<span class="tag">[',
			tag,
			IF(GROUP_CONCAT(dont) IS NOT NULL OR edit = 0 OR edit IS NULL, '',
				IFNULL(CONCAT(
					'\n',
					GROUP_CONCAT(KB_LINK(
						'do_action.php',
						notitie_id,
						dag,
						lesuur,
						CONCAT(
							'&action_id=',
							action_id,
							'&isgrp=',
							grp,
							'$common'),
						action_name)
					SEPARATOR '/')),
				'')
			),
			']</span>'), '') tags, notitie_id, grp
		FROM ( $inner_query ) bla2
		LEFT JOIN tags USING (tag_id)
		GROUP BY notitie_id, tag_id
	) AS bla3
	GROUP BY notitie_id
) AS bla4
GROUP BY lesuur, dag
EOT
);


//if ($_SESSION['type'] == 'leerling' && $_SESSION['teletop_session']) {
//	$ch = curl_teletop_init();
//	$data = curl_teletop_req($ch, '/tt/abvo/updates.nsf/a-SearchRosterRows?OpenAgent&rand='.rand(0,99999998), '&sw='.$week.'&sa=1&ug='.$row['naam'],
//		array('X-Requested-With: XMLHttpRequest'));
	//echo('test<br>');
//}

function print_dag($dag) {
	switch ($dag) {
		case 0: return 'zo';
		case 1: return 'ma';
		case 2: return 'di';
		case 3: return 'wo';
		case 4: return 'do';
		case 5: return 'vr';
		case 6: return 'za';
        }
}


function print_rev($time, $rev = 0) {
        return 'r'.$rev.' '.date('W', $time).print_dag(date('w', $time)).date('G:i', $time);
}

function print_diff($row) {
	$bla = array();
	if ($row['f_dag'] != $row['s_dag'] || $row['f_uur'] != $row['s_uur']) 
		$bla[] = print_dag($row['s_dag']).$row['s_uur'];
	if ($row['f_lesgroepen'] != $row['s_lesgroepen'])
		$bla[] = $row['s_lesgroepen'];
	if ($row['f_vakken'] != $row['s_vakken']) 
		$bla[] = $row['s_vakken'];
	if ($row['f_docenten'] != $row['s_docenten'])
		$bla[] = $row['s_docenten'];
	if ($row['f_lokalen'] != $row['s_lokalen'])
		$bla[] = $row['s_lokalen'];

	return implode('/', $bla);
}

$wijz_id = sprint_singular("SELECT MAX(rooster_id) FROM roostertest.weken2roosters JOIN roostertest.weken USING (week_id) WHERE week = $week");
if ($wijz_id) {
	$basis_id = sprint_singular("SELECT MAX(rooster_id) FROM roostertest.weken2roosters JOIN roostertest.weken USING (week_id) WHERE wijz_id = 0 AND week = $week");
} else {
	$wijz_id = $basis_id = sprint_singular("SELECT MAX(rooster_id) FROM roostertest.weken2roosters WHERE week_id < ( SELECT week_id FROM roostertest.weken WHERE week = $week ) AND wijz_id = 0");
}

if (!$_SESSION['toon_rooster'] || !$basis_id) {
	$roosterstatus = 'GEEN';
	goto out; // geen rooster
}

$result4 = mysql_query_safe("SELECT basis_id, wijz_id, timestamp FROM roostertest.weken2roosters JOIN roostertest.weken USING (week_id) WHERE rooster_id = $wijz_id");
$test = mysql_fetch_row($result4);
$roosterstatus = print_rev($test[2], $test[0].','.$test[1]);

if ($basis_id == $wijz_id) $wijz_id = 0;

function rquery_inner($where, $id1, $id2, $wijz) {
	return <<<EOQ
SELECT f.zermelo_id AS f_zermelo_id, f.dag AS f_dag, f.uur AS f_uur, f.vakken AS f_vakken,
	f.docenten AS f_docenten, f.lokalen AS f_lokalen, f.lesgroepen AS f_lesgroepen, f2g.grp_naam AS f_grp_naam, f2g.grp2vak_id AS f_grp2vak_id, f2g.grp_id AS f_grp_id,
	s.zermelo_id AS s_zermelo_id, s.dag AS s_dag, s.uur AS s_uur, s.vakken AS s_vakken,
	s.docenten AS s_docenten, s.lokalen AS s_lokalen, s.lesgroepen AS s_lesgroepen, s2g.grp_naam AS s_grp_naam, s2g.grp2vak_id AS s_grp2vak_id, s2g.grp_id AS s_grp_id, e2s.entity_id AS vis, $wijz AS wijz
FROM roostertest.entities2lessen AS e2f
JOIN roostertest.rooster2lessen AS r2f ON r2f.les_id = e2f.les_id AND r2f.rooster_id = $id1
JOIN roostertest.lessen AS f ON f.les_id = e2f.les_id
LEFT JOIN roostertest.lessen2grp2vak AS f2g ON f2g.les_id = r2f.les_id
LEFT JOIN (
	SELECT s.zermelo_id, s.dag, s.uur, s.vakken, s.docenten, s.lokalen, s.lesgroepen, s.les_id
	FROM roostertest.lessen AS s
	JOIN roostertest.rooster2lessen AS r2s ON r2s.les_id = s.les_id AND r2s.rooster_id = $id2
) AS s ON s.zermelo_id = f.zermelo_id
LEFT JOIN roostertest.entities2lessen AS e2s ON e2s.les_id = s.les_id AND e2s.entity_id = e2f.entity_id
LEFT JOIN roostertest.lessen2grp2vak AS s2g ON s2g.les_id = s.les_id
WHERE $where
EOQ;
}

function rquery($where, $id1, $id2) {
	return rquery_inner($where, $id1, $id2, 1).' UNION ALL '.rquery_inner($where, $id2, $id1, 0);
}

function rquery_new($entity_ids, $id1, $id2) {
	 return mysql_query_safe('SELECT * FROM ( '.rquery("e2f.entity_id IN ( $entity_ids ) ", $id1, $id2).' ) AS r ORDER BY f_uur, f_dag, wijz DESC, s_zermelo_id');
}

if ($_SESSION['type'] == 'personeel') {
	if ($doelgroep == 'zelf') {
		$rostertype = 2;
		$entity_ids = <<<EOQ
SELECT entities.entity_id
FROM roostertest.ovckb2entities
JOIN roostertest.entities ON entities.entity_id = ovckb2entities.entity_id AND entity_type = 2
WHERE ovckb_id = {$_SESSION['ppl_id']}
EOQ;
	} else if ($doelgroep == 'leerling') {
		$rostertype = 1;
		$entity_ids = <<<EOQ
SELECT entities.entity_id
FROM roostertest.ovckb2entities
JOIN roostertest.entities ON entities.entity_id = ovckb2entities.entity_id AND entity_type = 4
JOIN grp ON grp.grp_id = ovckb_id
JOIN ppl2grp USING (grp_id)
WHERE ppl_id = '$lln_id'
EOQ;
	} else if ($doelgroep == 'lesgroep') {
		$rostertype = 4;
		$entity_ids = <<<EOQ
SELECT DISTINCT entities.entity_id
FROM grp2vak
JOIN ppl2grp USING (grp_id)
JOIN ppl2grp AS grp2ppl USING (ppl_id)
JOIN roostertest.ovckb2entities ON grp2ppl.grp_id = ovckb2entities.ovckb_id
JOIN roostertest.entities ON entities.entity_id = ovckb2entities.entity_id AND entity_type = 4
WHERE grp2vak_id = '$grp2vak_id'
EOQ;

	}
	$result4 = rquery_new($entity_ids, $basis_id, $wijz_id);
} else if ($_SESSION['type'] == 'leerling') {
	$rostertype = 1;
	$entity_ids = <<<EOQ
SELECT entities.entity_id
FROM roostertest.ovckb2entities
JOIN roostertest.entities ON entities.entity_id = ovckb2entities.entity_id AND entity_type = 4
JOIN grp ON grp.grp_id = ovckb_id
JOIN ppl2grp USING (grp_id)
WHERE ppl_id = {$_SESSION['ppl_id']}
EOQ;
	$result4 = rquery_new($entity_ids, $basis_id, $wijz_id);
} else if ($_SESSION['type'] == 'ouder') {
	$type = sprint_singular("SELECT type FROM ppl WHERE ppl_id = '$lln_id'");
	if ($type == 'leerling') {
		$rostertype = 1;
		$entity_ids = <<<EOQ
SELECT entities.entity_id
FROM roostertest.ovckb2entities
JOIN roostertest.entities ON entities.entity_id = ovckb2entities.entity_id AND entity_type = 4
JOIN grp ON grp.grp_id = ovckb_id
JOIN ppl2grp USING (grp_id)
WHERE ppl_id = '$lln_id'
EOQ;
		$result4 = rquery_new($entity_ids, $basis_id, $wijz_id);
	} else if ($type == 'personeel') {
		$rostertype = 2;
		$entity_ids = <<<EOQ
SELECT entities.entity_id
FROM roostertest.ovckb2entities
JOIN roostertest.entities ON entities.entity_id = ovckb2entities.entity_id AND entity_type = 2
WHERE ovckb_id = '$lln_id'
EOQ;
		$result4 = rquery_new($entity_ids, $basis_id, $wijz_id);
	} 
}

out:

gen_html_header('Agenda'); 
status();

//$inner_test = mysql_query_safe($inner_query);
//if ($_SESSION['ppl_id'] == 3490) echo(sprint_table($inner_test));

?>
<form name="select" action="index.php" method="GET" accept-charset="UTF-8">
<p>week: <? prevweek($week); echo($week_options); nextweek($week); ?>
<? echo(get_rooster_link());?> <a href="https://abvo.itslearning.com/">It's Learning</a> <?
//$bla = urlencode('Klik dit window weg om terug te gaan naar onlineklassenboek.nl.');
if ($_SESSION['type']!='leerling' && $_SESSION['type'] != 'ouder'/* && $_SERVER['REMOTE_ADDR'] == '145.118.199.242'*/) {
		 ?> <a href="https://start7.mijnsom.nl/app/login/asg?0" target="_blank">som</a> <a href="http://onlineklassenboek.nl/handleiding/">Docentenhandleiding</a><? } if ($no_issues > 0 && $_SESSION['type'] == 'personeel') echo(" Je hebt $no_issues <a href=\"issues.php?doelgroep=zelf&grp2vak_id=$grp2vak_id&lln_id=$lln_id\">openstaande issues</a>."); ?>
<? if ($_SESSION['type'] == 'ouder') { ?>
	<input type="hidden" name="doelgroep" value="leerling">
	<p>leerling:<? echo($lln_options) ?> <a href="ouder_auth_request.php">klik hier om een broer(tje)/zus(je) toe te voegen</a>
<? } else { ?>
<? if ($grp2vak_select) { ?>
<p>doelgroep:
<? if ($_GET['doelgroep'] == "lesgroep") {
	$checked[1] = "checked "; $checked[0]= ""; $checked[2] = "";
} else if ($_GET['doelgroep'] == 'zelf') {
	$checked[0] = "checked "; $checked[1]= ""; $checked[2] = '';
} else {
	$checked[2] = "checked "; $checked[1]= ""; $checked[0] = '';
}
?>
<input type="radio" name="doelgroep" <? echo($checked[0]) ?>value="zelf" onclick="document.select.submit()">zelf</input>
<input type="radio" name="doelgroep" <? echo($checked[1]) ?>value="lesgroep" onclick="document.select.submit()">groep
<? echo($grp2vak_select) ?>
</input>
<? if ($lln_options) { ?> <input type="radio" name="doelgroep" <? echo($checked[2]) ?>value="leerling" onclick="document.select.submit()">leerling
<? echo($lln_options) ?>
</input>
<? } else { echo('heeft geen leden'); } ?>
<? } ?>
<? } ?>
</form>
<? if (isset($issues_links) && count($issues_links)) { ?>
<p>Je hebt <? if(count($issues_links) == 1) { ?>1 openstaand issue<? } else { echo(count($issues_links)) ?> openstaande issues<? } ?> met <? echo($lln_naam) ?>.
<p><? echo($issues_friendly) ?>
<? } ?>
<p style="text-align: center">
<? if ($week < 30) {
	$year = substr($schooljaar_long, 5);
} else {
	$year = substr($schooljaar_long, 0, 4);
}
$day_in_week = strtotime(sprintf("$year-01-04 + %d weeks", $week - 1));
$thismonday = $day_in_week - ((date('w', $day_in_week) + 6)%7)*24*60*60;
?>
<table style="table-layout: auto" border="1" width="100%">
<colgroup width="2.5%"></colgroup>
<colgroup width="19.5%" span="5"></colgroup>
<tr>
<th>
<th>maandag <?   echo date("j-n", $thismonday)          ?>
<th>dinsdag <?   echo date("j-n", $thismonday + 86400)  ?>
<th>woensdag <?  echo date("j-n", $thismonday + 172800) ?>
<th>donderdag <? echo date("j-n", $thismonday + 259200) ?>
<th>vrijdag <?   echo date("j-n", $thismonday + 345600) ?>

<? $row = mysql_fetch_row($testresult);
if (isset($result4) && $result4) {
	$rosterrow = mysql_fetch_array($result4);
} else $rosterrow = NULL;
for ($i = 1; $i <= 9; $i++) { ?>
<tr align="left" valign="top"><td><? echo '<span title="'.$lestijden[$i].'">'.$i.'</span>' ?></td>
<?	for ($j = 1; $j <= 5; $j++) {
		echo('<td>');
		if (is_array($row) && $row[0] == $j && $row[1] == $i) {
			echo($row[2]);
			$row = mysql_fetch_row($testresult);
		} 
		$dayanduur = $j.'&lesuur='.$i;
		echo($plus_start.$dayanduur.$plus_end);
		if (isset($issues_links) && is_array($issues_links)) foreach ($issues_links as $value) echo(sprintf($value, $dayanduur));
		while (is_array($rosterrow) && $rosterrow['f_dag'] == $j && $rosterrow['f_uur'] == $i) {
			$extra = '';
			$text = '';
			if ($rosterrow['s_zermelo_id'] && !$rosterrow['s_dag'] && $rosterrow['wijz']) $extra = ' uitval';
			else if (!$rosterrow['s_zermelo_id'] && !$rosterrow['wijz']) $extra = ' extra';
			else if ($rosterrow['f_dag'] == $rosterrow['s_dag'] && $rosterrow['f_uur'] == $rosterrow['s_uur'] && $rosterrow['vis']) {
				// les is niet in tijd verplaatst, maar wel gewijzigd, beide zijn zichtbaar
				if ($rosterrow['wijz']) { // dit is de oude les, skip
					$rosterrow = mysql_fetch_array($result4);
					continue;
				} else if ( $rosterrow['f_vakken'] != $rosterrow['s_vakken'] ||
					$rosterrow['f_docenten'] != $rosterrow['s_docenten'] ||
					$rosterrow['f_lokalen'] != $rosterrow['s_lokalen'] ||
					$rosterrow['f_lesgroepen'] != $rosterrow['s_lesgroepen']) {
						$extra = ' gewijzigd';
						$text = ' <- '.print_diff($rosterrow);
				}
			} else if ($rosterrow['wijz'] && $rosterrow['s_zermelo_id']) {
				$text = ' -> '.print_diff($rosterrow);
				$extra = ' verplaatstnaar';
			} else if (!$rosterrow['wijz'] && $rosterrow['s_zermelo_id']) {
				$text = ' <- '.print_diff($rosterrow);
				$extra = ' verplaatstvan';
			}
			$info = array();
			if ($rostertype != 1) {
				$grp_naam = NULL;
				if ($rosterrow['f_grp_naam']) {
					if ($grp_id != $rosterrow['f_grp_id']) 
						$grp_naam = $rosterrow['f_grp_naam'];
						//$info[] = $rosterrow['f_grp_naam'].'('.$rosterrow['f_grp_id'].')';
				} else if ($rosterrow['f_lesgroepen']) $grp_naam = $rosterrow['f_lesgroepen'];
				if ($grp_naam) $info[] = $grp_naam;
				// check of het vak al in de naam van de lesgroep zit
				if (!$grp_naam || !preg_match("/{$rosterrow['f_vakken']}[0-9]?/i", $grp_naam)) $info[] = $rosterrow['f_vakken'];
			} else if ($rosterrow['f_vakken']) $info[] = $rosterrow['f_vakken'];
			if ($rostertype != 2) if ($rosterrow['f_docenten']) $info[] = $rosterrow['f_docenten'];
			if ($rosterrow['f_lokalen']) $info[] = $rosterrow['f_lokalen'];
			echo(' <span class="roster'.$extra.'">'.implode('/', $info).$text.'</span>');
			//print_r($rosterrow);
			$rosterrow = mysql_fetch_array($result4);
		}
	}
}
?>
</table>
</p>
<p>Persoonlijke notities zijn <span class='pers'>blauw</span>. 
<? if ($_SESSION['toon_rooster']) {
echo(sprint_ahref_parms('roster.php', '[verberg rooster]', $http_get, 'show', 0));
?> Roosterupdate: <? echo $roosterstatus ?>.<?
} else {
echo(sprint_ahref_parms('roster.php', '[toon rooster]', $http_get, 'show', 1));
} ?>
<? gen_html_footer(); ?>