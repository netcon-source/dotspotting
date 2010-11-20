<?php

	#
	# $Id$
	#

	#################################################################

	loadlib("dots_derive");
	loadlib("dots_lookup");
	loadlib("dots_search");

	loadlib("geo_utils");

	#################################################################

	$GLOBALS['dots_lookup_local_cache'] = array();
	$GLOBALS['dots_local_cache'] = array();

	#################################################################

	function dots_permissions_map($string_keys=0){

		$map = array(
			0 => 'public',
			1 => 'private',
		);

		if ($string_keys){
			$map = array_flip($map);
		}

		return $map;
	}

	#################################################################

	function dots_import_dots(&$user, &$sheet, &$dots, $more=array()){

		$received = 0;
		$processed = 0;

		$errors = array();
		$search = array();

		$timings = array(
			0 => 0
		);

		$start_all = microtime_ms() / 1000;

		# As in: don't update DotsSearch inline but save
		# all the inserts and do them at at end. 

		$more['batch_search_update'] = 1;

		foreach ($dots as $dot){

			$received ++;

			$start = microtime_ms() / 1000;

			$rsp = dots_create_dot($user, $sheet, $dot, $more);

			$end = microtime_ms() / 1000;

			$timings[ $received ] = $end - $start;

			if (! $rsp['ok']){
				$rsp['record'] = $received;
				$errors[] = $rsp;

				continue;
			}

			if (isset($rsp['search'])){
				$search[] = $rsp['search'];
			}

			$processed ++;
		}

		#

		if (count($search)){

			$search_rsp = dots_search_add_lots_of_dots($search);

			if (! $search_rsp){
				# What then ?
			}
		}

		#

		$end_all = microtime_ms() / 1000;
		$timings[0] = $end_all - $start_all;

		$ok = ($processed) ? 1 : 0;

		return array(
			'ok' => $ok,
			'errors' => &$errors,
			'timings' => &$timings,
			'dots_received' => $received,
			'dots_processed' => $processed,
		);

	}

	#################################################################

	function dots_create_dot(&$user, &$sheet, &$data, $more=array()){

		# if we've gotten here via lib_uploads then
		# we will have already done validation.

		if (! $more['skip_validation']){

			$rsp = dots_ensure_valid_data($row);

			if (! $rsp['ok']){
				return $rsp;
			}
		}

		#

		$id = dbtickets_create(64);

		if (! $id){
			return array(
				'ok' => 0,
				'error' => 'Ticket server failed',
			);
		}

		#
		# Assign basic geo bits - keep track of stuff that has
		# been derived so that we can flag them accordingly in
		# the DotsExtras table.
		#

		list($data, $derived) = dots_derive_location_data($data);

		#
		# creation date for the point (different from import date)
		# should this be stored/flagged as an extra?
		#

		$now = time();

		if ($created = $data['created']){

			#
			# Because intval("2010-09-23T00:18:55Z") returns '2010' ...
			# Because is_numeric(20101029154025.000) returns true ...
			# Because strtotime(time()) returns false ...
			# BECAUSE GOD HATES YOU ...
			#

			$created = (preg_match("/^\d+$/", $created)) ? $created : strtotime($created);

			# if ! $created then reassign $now ?

			# Now convert everything back in to a datetime string

			if ($created){
				$data['created'] = gmdate('Y-m-d H:i:s', $created);
			}
		}

		else {
			$data['created'] = gmdate('Y-m-d H:i:s', $now);
		}

		#
		# permissions
		#

		$perms_map = dots_permissions_map('string keys');
		$perms = $perms_map['public'];

		if (($data['perms'] == 'private') || ($more['mark_all_private'])){
			$perms = $perms_map['private'];
		}

		#
		# Go! Or rather... start!
		#

		$dot = array(
			'id' => $id,
			'user_id' => $user['id'],
			'sheet_id' => $sheet['id'],
			'perms' => $perms,
		);
					
		$to_denormalize = array(
			'created',

			# Maybe on these but not. Maybe, as in
			# for sorting but that might be easier
			# in JS? (20101120/straup)
			# 'location',
			# 'type',
		);

		foreach ($to_denormalize as $key){

			if ((isset($data[$key])) && (! empty($data[$key]))){
				$dot[$key] = $data[$key];
			}
		}

		#
		# Add any "extras"
		#

		$details = array();

		foreach (array_keys($data) as $label){

			$label = filter_strict(trim($label));

			if (! $label){
				continue;
			}

			$value = $data[$label];
			$value = filter_strict(trim($value));

			if (! $value){
				continue;
			}

			$ns = null;
			$pred = $label;

			if (strpos($label, ':')){
				list($ns, $pred) = explode(':', $label, 2);
			}

			$detail = array(
				'namespace' => $ns,
				'label' => $pred,
				'value' => $data[$label],
			);

			if (isset($derived[$label])){

				$extra['derived_from'] = $derived[$label];
			}

			if (! is_array($details[$label])){
				$details[$label] = array();
			}

			$details[$label][] = $detail;
		}

		$dot['details_json'] = json_encode($details);

		#
		# Look, we are creating the dot now
		#

		$rsp = db_insert_users($user['cluster_id'], 'Dots', $dot);

		if (! $rsp['ok']){
			return $rsp;
		}

		$dot['extras'] = $extras;

		#
		# Update the DotsLookup table
		#

		$lookup = array(
			'dot_id' => $id,
			'sheet_id' => $sheet['id'],
			'user_id' => $user['id'],
			'imported' => $now,
			'last_modified' => $now,
		);

		$lookup_rsp = dots_lookup_create($lookup);

		if (! $lookup_rsp['ok']){
			# What then...
		}

		#
		# Now the searching
		#

		$search = array(
			'dot_id' => $id,
			'sheet_id' => $sheet['id'],
			'user_id' => $user['id'],
			'imported' => $now,
			'created' => $data['created'],
			'perms' => $perms,
			'type' => $data['type'],
			'location' => $data['location'],
			'geohash' => $data['geohash'],
		);

		#
		# Don't assign empty strings for lat/lon because MySQL will
		# store them as 0.0 rather than NULLs
		# 

		foreach (array('latitude', 'longitude') as $coord){

			if (is_numeric($data[$coord])){
				$search[$coord] = $data[$coord];
			}
		}

		if ($more['batch_search_update']){
			$rsp['search'] = &$search;
		}

		else {
			$search_rsp = dots_search_add_dot($search);

			if (! $search_rsp['ok']){
				# What then...
			}
		}

		#
		# Happy happy
		#

		$rsp['dot'] = &$dot;
		return $rsp;
	}

	#################################################################

	function dots_update_dot(&$dot, $update){

		$user = users_get_by_id($dot['user_id']);

		$enc_id = AddSlashes($dot['id']);
		$where = "id='{$enc_id}'";

		foreach ($update as $k => $v){
			$update[$k] = AddSlashes($v);
		}

		$rsp = db_update_users($user['cluster_id'], 'Dots', $update, $where);

		if ($rsp['ok']){
			unset($GLOBALS['dots_local_cache'][$dot['id']]);
		}

		#
		# Update search: TODO
		#

		#
		# Update the lookup table?
		#

		$sheet = sheets_get_sheet($dot['sheet_id']);
		$count_rsp = sheets_update_dot_count_for_sheet($sheet);

		$lookup_update = array(
			'last_modified' => $now,
		);

		$lookup_where = "dot_id='{$enc_id}'";

		$lookup_rsp = db_update('DotsLookup', $lookup_update, $lookup_where);

		if (! $lookup_rsp['ok']){
			# What?
		}

		# Happy!		

		return $rsp;
	}

	#################################################################

	function dots_delete_dot(&$dot, $more=array()){

		#
		# Update the search table
		#

 		if (! isset($more['skip_update_search'])){

			$search_rsp = dots_search_remove_dot($dot);

			if (! $search_rsp['ok']){
				# What?
			}
		}

		$user = users_get_by_id($dot['user_id']);

		$enc_id = AddSlashes($dot['id']);

		$sql = "DELETE FROM Dots WHERE id='{$enc_id}'";
		$rsp = db_write_users($user['cluster_id'], $sql);

		if (! $rsp['ok']){
			return $rsp;
		}

 		if (! isset($more['skip_update_sheet'])){

			$sheet = sheets_get_sheet($dot['sheet_id']);

			$rsp2 = sheets_update_dot_count_for_sheet($sheet);
			$rsp['update_sheet_count'] = $rsp2['ok'];
		}
		
		#
		# Update the lookup table
		#

		$lookup_update = array(
			'deleted' => time(),
		);

		$lookup_rsp = dots_lookup_update($dot, $lookup_update);

		if (! $lookup_rsp['ok']){
			# What?
		}

		#

		if ($rsp['ok']){
			unset($GLOBALS['dots_local_cache'][$dot['id']]);
		}

		return $rsp;
	}

	#################################################################

	function dots_get_extent_for_sheet(&$sheet, $viewer_id=0){

		$enc_id = AddSlashes($sheet['id']);

		$sql = "SELECT MIN(latitude) AS swlat, MIN(longitude) AS swlon, MAX(latitude) AS nelat, MAX(longitude) AS nelon FROM DotsSearch WHERE sheet_id='{$enc_id}'";

		if ($viewer_id !== $sheet['user_id']){

			$sql = _dots_where_public_sql($sql);
		}

		return db_single(db_fetch($sql));
	}

	#################################################################

	#
	# Grab the sheet from db_main
	#

	function dots_lookup_dot($dot_id){

		if (isset($GLOBALS['dots_lookup_local_cache'][$dot_id])){
			return $GLOBALS['dots_lookup_local_cache'][$dot_id];
		}

		$enc_id = AddSlashes($dot_id);

		$sql = "SELECT * FROM DotsLookup WHERE dot_id='{$enc_id}'";
		$rsp = db_fetch($sql);

		if ($rsp['ok']){
			$GLOBALS['dots_lookup_local_cache'][$dot_id] = $rsp;
		}

		return db_single($rsp);
	}

	#################################################################

	#
	# Fetch the dot from the shards
	#	

	function dots_get_dot($dot_id, $viewer_id=0, $more=array()){

		# Can has cache! Note this is just the raw stuff
		# from the Dots table and that 'details' get loaded
		# below.

		if (isset($GLOBALS['dots_local_cache'][$dot_id])){
			$dot = $GLOBALS['dots_local_cache'][$sot_id];
		}

		else {

			# This is the kind of thing that would be set by lib_search

			if ($user_id = $more['dot_user_id']){
				$user = users_get_by_id($more['dot_user_id']);
			}

			else {
				$lookup = dots_lookup_dot($dot_id);

				if (! $lookup){
					return;
				}

				if ($lookup['deleted']){
					return array(
						'id' => $lookup['dot_id'],
						'deleted' => $lookup['deleted'],
					);
				}

				$user = users_get_by_id($lookup['user_id']);
			}

			if (! $user){
				return;
			}

			$enc_id = AddSlashes($dot_id);
			$enc_user = AddSlashes($user['id']);

			$sql = "SELECT * FROM Dots WHERE id='{$enc_id}'";

			if ($viewer_id !== $user['id']){
				$sql = _dots_where_public_sql($sql);
			}

			$rsp = db_fetch_users($user['cluster_id'], $sql);
			$dot = db_single($rsp);

			if ($rsp['ok']){
				$GLOBALS['dots_local_cache'][$dot_id] = $dot;
			}
		}

		if ($dot){
			$more['load_sheet'] = 1;
			dots_load_details($dot, $viewer_id, $more);
		}

		return $dot;
	}

	#################################################################

	function dots_can_view_dot(&$dot, $viewer_id){

		if ($dot['user_id'] == $viewer_id){
			return 1;
		}

		$perms_map = dots_permissions_map();

		return ($perms_map[$dot['perms']] == 'public') ? 1 : 0;		
	}

	#################################################################

	#
	# I am not (even a little bit) convinced this is a particularly
	# awesome way to do this. But it's a start. For now.
	# (20101026/straup)
	#
	
	function dots_get_dots_recently_imported($to_fetch=15){

		$recent = array();

		$sheet_sql = "SELECT * FROM SheetsLookup WHERE deleted=0 ORDER BY created DESC";
		$sheet_args = array( 'page' => 1 );

		$page_count = null;
		$total_count = null;

		$iters = 0;
		$max_iters = 15;

		while((! isset($page_count)) || ($page_count >= $sheet_args['page'])){

			$sheet_rsp = db_fetch_paginated($sheet_sql, $sheet_args);

			if (! $sheet_rsp['ok']){
				break;
			}

			if (! isset($page_count)){
				$page_count = $sheet_rsp['pagination']['page_count'];
				$total_count = $sheet_rsp['pagination']['total_count'];
			}

			foreach ($sheet_rsp['rows'] as $sheet){

				$enc_sheet = AddSlashes($sheet['sheet_id']);

				$dot_sql = "SELECT * FROM DotsSearch WHERE sheet_id='{$enc_sheet}' AND perms=0 ORDER BY imported DESC";
				$dot_args = array( 'per_page' => 15 );

				$dot_rsp = db_fetch_paginated($dot_sql, $dot_args);

				if (! $dot_rsp['ok']){
					break;
				}

				$default_limit = 3;	# sudo, make me smarter
				$limit = min($default_limit, count($dot_rsp['rows']));

				if ($limit){

					shuffle($dot_rsp['rows']);

					foreach (array_slice($dot_rsp['rows'], 0, $limit) as $row){

						$viewer_id = 0;
						$more = array('load_user' => 1);

						$recent[] = dots_get_dot($row['dot_id'], $viewer_id, $more);
					}

					if (count($recent) == $to_fetch){
						break;
					}
				}
			}

			if (count($recent) == $to_fetch){
				break;
			}

			$sheet_args['page'] ++;
			$iters ++;

			if ($iters == $max_iters){
				break;
			}
		}

		shuffle($recent);
		return $recent;
	}

	#################################################################

	function dots_get_dots_for_sheet(&$sheet, $viewer_id=0, $more=array()){

		$user = users_get_by_id($sheet['user_id']);

		$enc_id = AddSlashes($sheet['id']);

		$sql = "SELECT * FROM Dots WHERE sheet_id='{$enc_id}'";

		if ($viewer_id !== $sheet['user_id']){

			$sql = _dots_where_public_sql($sql);
		}

		$order_by = 'id';
		$order_sort = 'ASC';

		# check $args here for additioning sorting

		$order_by = AddSlashes($order_by);
		$order_sort = AddSlashes($order_sort);

		$sql .= " ORDER BY {$order_by} {$order_sort}";

		$rsp = db_fetch_paginated_users($user['cluster_id'], $sql, $more);
		$dots = array();

		foreach ($rsp['rows'] as $dot){

			dots_load_details($dot, $viewer_id, $more);
			$dots[] = $dot;
		}

		return $dots;
	}

	#################################################################

	function dots_get_dots_for_user(&$user, $viewer_id=0, $more=array()) {

		$enc_id = AddSlashes($user['id']);

		$sql = "SELECT * FROM Dots WHERE user_id='{$enc_id}'";

		if ($viewer_id !== $user['id']){

			$sql = _dots_where_public_sql($sql, 1);
		}

		$order_by = 'id';
		$order_sort = 'DESC';

		# check $args here for additioning sorting

		$order_by = AddSlashes($order_by);
		$order_sort = AddSlashes($order_sort);

		$sql .= " ORDER BY {$order_by} {$order_sort}";

		$rsp = db_fetch_paginated_users($user['cluster_id'], $sql, $more);
		$dots = array();

		$even_more = array(
			'load_sheet' => 1,
		);

		foreach ($rsp['rows'] as $dot){

			dots_load_details($dot, $viewer_id, $even_more);
			$dots[] = $dot;
		}

		return $dots;
	}

	#################################################################

	function dots_count_dots_for_sheet(&$sheet){

		$user = users_get_by_id($sheet['user_id']);
		$enc_id = AddSlashes($sheet['id']);

		$sql = "SELECT COUNT(id) AS count_total FROM Dots WHERE sheet_id='{$enc_id}'";

		$rsp = db_fetch_users($user['cluster_id'], $sql);
		$row = db_single($rsp);

		$count_total = $row['count_total'];

		$sql = "SELECT COUNT(id) AS count_public FROM Dots WHERE sheet_id='{$enc_id}'";

		$sql = _dots_where_public_sql($sql);

		$rsp = db_fetch_users($user['cluster_id'], $sql);
		$row = db_single($rsp);

		$count_public = $row['count_public'];

		return array(
			'total' => $count_total,
			'public' => $count_public,
		);
	}

	#################################################################

	# Note the pass-by-ref

	function dots_load_details(&$dot, $viewer_id=0, $more=array()){

		$dot['details'] = json_decode($dot['details_json'], 1);

		$geo_bits = array(
			'latitude',
			'longitude',
			'altitude',
			'geohash'
		);

		foreach ($geo_bits as $what){

			if (isset($dot['details'][$what])){
				$dot[$what] = $dot['details'][$what][0]['value'];
			}
		}

		$listview = array();

		foreach ($dot['details'] as $label => $ignore){

			if (! isset($dot[$label])){
				$listview[] = $label;
			}
		}
		
		$dot['details_listview'] = implode(", ", $listview);

		#

		if ($more['load_sheet']){

			$sheet_more = array(
				'sheet_user_id' => $dot['user_id'],
			);

	 		$dot['sheet'] = sheets_get_sheet($dot['sheet_id'], $viewer_id, $sheet_more);
		}

		if ($more['load_user']){
			$dot['user'] = users_get_by_id($dot['user_id']);
		}
	}

	#################################################################

	function dots_ensure_valid_data(&$data){

		$skip_required_latlon = 0;

		if (isset($data['address']) && ((empty($data['latitude'])) || (empty($data['longitude'])))){

			$skip_required_latlon = 1;

			# It is unclear whether this should really return an
			# error - perhaps it should simply add the dot with
			# NULL lat/lon values and rely on a separate cron job
			# to clean things up with geocoding is re-enabled.
			# (20101023/straup)

			if (! $GLOBALS['cfg']['enable_feature_geocoding']){
				return array( 'ok' => 0, 'error' => 'Geocoding is disabled.' );
			}

			if (strlen(trim($data['address'])) == 0){
				return array( 'ok' => 0, 'error' => 'Address is empty.' );
			}
		}

		else {

			if (! isset($data['latitude'])){
				return array( 'ok' => 0, 'error' => 'missing latitude' );
			}

			if (! isset($data['longitude'])){
				return array( 'ok' => 0, 'error' => 'missing longitude' );
			}

			if (! geo_utils_is_valid_latitude($data['latitude'])){
				return array( 'ok' => 0, 'error' => 'invalid latitude' );
			}

			if (! geo_utils_is_valid_longitude($data['longitude'])){
				return array( 'ok' => 0, 'error' => 'invalid longitude' );
			}
		}

		return array( 'ok' => 1 );
	}

	#################################################################

	#
	# Do not include any dots that may in the queue
	# waiting to be geocoded, etc.
	#

	function _dots_where_public_sql($sql, $has_where=1){

		$where .= ($has_where) ? "AND" : "WHERE";

		$sql .= " {$where} perms=0";
		return $sql;
	}

	#################################################################
?>
