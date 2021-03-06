<?php
/** ---------------------------------------------------------------------
 * app/helpers/importHelpers.php : 
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2013-2014 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 * 
 * @package CollectiveAccess
 * @subpackage utils
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 * 
 * ----------------------------------------------------------------------
 */

 /**
   *
   */
   require_once(__CA_LIB_DIR__.'/core/Logging/KLogger/KLogger.php');
   require_once(__CA_LIB_DIR__.'/ca/Import/BaseDataReader.php');

	# ---------------------------------------
	/**
	 * 
	 *
	 * @param string $ps_refinery_name
	 * @param string $ps_table
	 * @param array $pa_parents 
	 * @param array $pa_source_data
	 * @param array $pa_item
	 * @param int $pn_c
	 * @param KLogger $o_log
	 * 
	 * @return int
	 */
	function caProcessRefineryParents($ps_refinery_name, $ps_table, $pa_parents, $pa_source_data, $pa_item, $pn_c, $pa_options=null) {

		global $g_ui_locale_id;
		if (!is_array($pa_options)) { $pa_options = array(); }
		
		$o_log = caGetOption('log', $pa_options, null);
		$o_reader = caGetOption('reader', $pa_options, null);
		$o_trans = caGetOption('transaction', $pa_options, null);

		
		$vn_list_id = caGetOption('list_id', $pa_options, null);
		$vb_hierarchy_mode = caGetOption('hierarchyMode', $pa_options, false);
		
		if (!is_array($pa_parents)) { $pa_parents = array($pa_parents); }
		$vn_id = null;
		
		$pa_parents = array_reverse($pa_parents);
		foreach($pa_parents as $vn_i => $va_parent) {
			$vs_name = BaseRefinery::parsePlaceholder($va_parent['name'], $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader, 'returnAsString' => true, 'delimiter' => ' '));
			$vs_idno = BaseRefinery::parsePlaceholder($va_parent['idno'], $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader, 'returnAsString' => true, 'delimiter' => ' '));
			$vs_type = BaseRefinery::parsePlaceholder($va_parent['type'], $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader, 'returnAsString' => true, 'delimiter' => ' '));

			if (!$vs_name && !$vs_idno) { continue; }
			if (!$vs_name) { $vs_name = $vs_idno; }
			
			$va_attributes = (isset($va_parent['attributes']) && is_array($va_parent['attributes'])) ? $va_parent['attributes'] : array();
			
			foreach($va_attributes as $vs_element_code => $va_attrs) {
				if(is_array($va_attrs)) {
					foreach($va_attrs as $vs_k => $vs_v) {
						// BaseRefinery::parsePlaceholder may return an array if the input format supports repeated values (as XML does)
						// DataMigrationUtils::getCollectionID(), which ca_data_importers::importDataFromSource() uses to create related collections
						// only supports non-repeating attribute values, so we join any values here and call it a day.
						$va_attributes[$vs_element_code][$vs_k] = BaseRefinery::parsePlaceholder($vs_v, $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader, 'returnAsString' => true, 'delimiter' => ' '));
					}
				} else {
					$va_attributes[$vs_element_code] = array($vs_element_code => BaseRefinery::parsePlaceholder($va_attrs, $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader, 'returnAsString' => true, 'delimiter' => ' ')));
				}
			}
			
			$va_attributes['idno'] = $vs_idno;
			$va_attributes['parent_id'] = $vn_id;
			
			if (isset($va_parent['rules']) && is_array($va_parent['rules'])) { 
				foreach($va_parent['rules'] as $va_rule) {
					$vm_ret = ExpressionParser::evaluate($va_rule['trigger'], $pa_source_data);
					if (!ExpressionParser::hadError() && (bool)$vm_ret) {
						foreach($va_rule['actions'] as $va_action) {
							if (!is_array($va_action) && (strtolower($va_action) == 'skip')) {
								$va_action = array('action' => 'skip');
							}
							switch($vs_action_code = strtolower($va_action['action'])) {
								case 'set':
									switch($va_action['target']) {
										case 'name':
											$vs_name = BaseRefinery::parsePlaceholder($va_action['value'], $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader, 'returnAsString' => true, 'delimiter' => ' '));
											break;
										case 'type':
											$vs_type = BaseRefinery::parsePlaceholder($va_action['value'], $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader, 'returnAsString' => true, 'delimiter' => ' '));
											break;
										default:
											$va_attributes[$va_action['target']] = BaseRefinery::parsePlaceholder($va_action['value'], $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader, 'returnAsString' => true, 'delimiter' => ' '));
											break;
									}
									break;
								case 'skip':
								default:
									if ($o_log) { 
										if ($vs_action_code != 'skip') {
											$o_log->logInfo(_t('[%3] Parent was skipped using rule "%1" with default action because an invalid action ("%2") was specified', $va_rule['trigger'], $vs_action_code, $ps_refinery_name));
										} else {
											$o_log->logDebug(_t('[%3] Parent was skipped using rule "%1" with action "%2"', $va_rule['trigger'], $vs_action_code, $ps_refinery_name));
										}
									}
									continue(4);
									break;
							}
						}
					} elseif (ExpressionParser::hadError() && $o_log) {
						$o_log->logError(_t('[%3] Error processing rule "%1" as an error occurred. Error number was "%2"', $va_rule['trigger'], ExpressionParser::$s_last_error, $ps_refinery_name));
					}
				}
			}
			
			$va_match_on = caGetOption("{$ps_refinery_name}_dontMatchOnLabel", $pa_item['settings'], false) ? array('idno') : array('idno', 'label');
			$pa_options = array_merge(array('matchOn' => $va_match_on), $pa_options);
			
			switch($ps_table) {
				case 'ca_objects':
					$vn_id = DataMigrationUtils::getObjectID($vs_name, $vn_id, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					$va_attributes['preferred_labels']['name'] = $va_attributes['_preferred_labels'] = $vs_name;
					break;
				case 'ca_entities':
					$vn_id = DataMigrationUtils::getEntityID($va_entity_label = DataMigrationUtils::splitEntityName($vs_name), $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					$va_attributes['preferred_labels'] = $va_entity_label;
					$va_attributes['_preferred_labels'] = $vs_name;
					break;
				case 'ca_places':
					if(!$vn_id) {	// get place hierarchy root
						require_once(__CA_MODELS_DIR__."/ca_places.php");
						$t_place = new ca_places();
						if ($o_trans) { $t_place->setTransaction($o_trans); }
						$vn_id = $pa_options['defaultParentID'];
						if(!$vn_id){
							$vn_id = $t_place->getHierarchyRootID($pa_options['hierarchyID']);
						}
						$va_attributes['parent_id'] = $vn_id;
					}
					$vn_id = DataMigrationUtils::getPlaceID($vs_name, $vn_id, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					$va_attributes['preferred_labels']['name'] = $va_attributes['_preferred_labels'] = $vs_name;
					break;
				case 'ca_occurrences':
					$vn_id = DataMigrationUtils::getOccurrenceID($vs_name, $vn_id, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					$va_attributes['preferred_labels']['name'] = $va_attributes['_preferred_labels'] = $vs_name;
					break;
				case 'ca_collections':
					$vn_id = DataMigrationUtils::getCollectionID($vs_name, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					$va_attributes['preferred_labels']['name'] = $va_attributes['_preferred_labels'] = $vs_name;
					break;
				case 'ca_loans':
					$vn_id = DataMigrationUtils::getLoanID($vs_name, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					$va_attributes['preferred_labels']['name'] = $va_attributes['_preferred_labels'] = $vs_name;
					break;
				case 'ca_movements':
					$vn_id = DataMigrationUtils::getMovementID($vs_name, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					$va_attributes['preferred_labels']['name'] = $va_attributes['_preferred_labels'] = $vs_name;
					break;
				case 'ca_list_items':
					if (!$vn_list_id) {
						if ($o_log) { $o_log->logDebug(_t('[importHelpers:caProcessRefineryParents] List was not specified')); }
						return null;
					}
					if(!$vn_id) {	// get place hierarchy root
						require_once(__CA_MODELS_DIR__."/ca_lists.php");
						$t_list = new ca_lists();
						if ($o_trans) { $t_list->setTransaction($o_trans); }
						$vn_id = $t_list->getRootItemIDForList($vn_list_id);
						$va_attributes['parent_id'] = $vn_id;
					}
					$vn_id = DataMigrationUtils::getListItemID($vn_list_id, $vs_name, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					$va_attributes['preferred_labels']['name_singular'] = $va_attributes['preferred_labels']['name_plural'] = $vs_name;
					break;
				case 'ca_storage_locations':
					if(!$vn_id) {	// get storage location hierarchy root
						require_once(__CA_MODELS_DIR__."/ca_storage_locations.php");
						$t_loc = new ca_storage_locations();
						if ($o_trans) { $t_loc->setTransaction($o_trans); }
						$vn_id = $t_loc->getHierarchyRootID();
						$va_attributes['parent_id'] = $vn_id;
					}
					$vn_id = DataMigrationUtils::getStorageLocationID($vs_name, $vn_id, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					$va_attributes['preferred_labels']['name'] = $va_attributes['_preferred_labels'] = $vs_name;
					break;
				default:
					if ($o_log) { $o_log->logDebug(_t('[importHelpers:caProcessRefineryParents] Invalid table %1', $ps_table)); }
					return null;
					break;	
			}
			$va_attributes['locale_id'] = $g_ui_locale_id;
			if ($o_log) { $o_log->logDebug(_t('[%6] Got parent %1 (%2) with id %3 and type %4 for %5', $vs_name, $vs_idno, $vn_id, $vs_type, $vs_name, $ps_refinery_name)); }
		}
		
		if ($vb_hierarchy_mode) {
			return $va_attributes;
		}
		return $vn_id;
	}
	# ---------------------------------------
	/**
	 * 
	 *
	 * @param array $pa_attributes 
	 * @param array $pa_source_data
	 * @param array $pa_item
	 * @param int $pn_c
	 * @param KLogger $o_log
	 * 
	 * @return array
	 */
	function caProcessRefineryAttributes($pa_attributes, $pa_source_data, $pa_item, $pn_c, $pa_options=null) {
		$o_log = caGetOption('log', $pa_options, null);
		$o_reader = caGetOption('reader', $pa_options, null);
		$o_trans = caGetOption('transaction', $pa_options, null);
		
		if (is_array($pa_attributes)) {
			$va_attr_vals = array();
			foreach($pa_attributes as $vs_element_code => $va_attrs) {
				$vb_is_repeating = false;
				$vn_num_repeats = null;
				if(is_array($va_attrs)) {
					foreach($va_attrs as $vs_k => $vs_v) {
						// BaseRefinery::parsePlaceholder may return an array if the input format supports repeated values (as XML does)
						
						$va_vals = BaseRefinery::parsePlaceholder($vs_v, $pa_source_data, $pa_item, $pn_c, array('delimiter' => caGetOption('delimiter', $pa_options, null), 'reader' => $o_reader));

						if (sizeof($va_vals) > 1) { $vb_is_repeating = true; }
						
						if ($vb_is_repeating) {
							if (is_null($vn_num_repeats)) { $vn_num_repeats = sizeof($va_vals); }
							
							$vn_c = 0;
							foreach($va_vals as $vn_x => $va_v) {
								$va_attr_vals[$vs_element_code][$vn_x][$vs_k] = $va_v;
								$vn_c++;
								if ($vn_c >= $vn_num_repeats) { break; }
							}
						} else {
							$va_attr_vals[$vs_element_code][$vs_k] = (is_array($vm_v = BaseRefinery::parsePlaceholder($vs_v, $pa_source_data, $pa_item, $pn_c, array('delimiter' => caGetOption('delimiter', $pa_options, null), 'reader' => $o_reader)))) ? join(" ", $vm_v) : $vm_v;
						}
					}
				} else {
					$va_attr_vals[$vs_element_code][$vs_element_code] = (is_array($vm_v = BaseRefinery::parsePlaceholder($va_attrs, $pa_source_data, $pa_item, $pn_c, array('returnDelimitedValueAt' => $pn_c,'delimiter' => caGetOption('delimiter', $pa_options, null), 'reader' => $o_reader)))) ? join(" ", $vm_v) : $vm_v;
				}
			}
			return $va_attr_vals;
		}
		return null;
	}
	# ---------------------------------------
	/**
	 * 
	 *
	 * @param string $ps_refinery_name 
	 * @param mixed $pm_import_tablename_or_num 
	 * @param mixed $pm_target_tablename_or_num 
	 * @param array $pa_source_data
	 * @param array $pa_item
	 * @param int $pn_c
	 * @param KLogger $o_log
	 * 
	 * @return array
	 */
	function caProcessInterstitialAttributes($ps_refinery_name, $pm_import_tablename_or_num, $pm_target_tablename_or_num, $pa_source_data, $pa_item, $pn_c, $pa_options=null) {
		$o_reader = caGetOption('reader', $pa_options, null);
		$o_log = caGetOption('log', $pa_options, null);
		$o_trans = caGetOption('transaction', $pa_options, null);
		
		if (is_array($pa_item['settings']["{$ps_refinery_name}_interstitial"])) {
			$o_dm = Datamodel::load();
			if (!($ps_import_tablename = $o_dm->getTableName($pm_import_tablename_or_num))) { return null; }
			if (!($ps_target_tablename = $o_dm->getTableName($pm_target_tablename_or_num))) { return null; }
			if (!($t_target = $o_dm->getInstanceByTableName($ps_target_tablename, true))) { return null; }
			if ($o_trans) { $t_target->setTransaction($o_trans); }
			$va_attr_vals = array();
					
			// What is the relationship table?
			if ($ps_import_tablename && $ps_target_tablename) {
				
				$vs_linking_table = null;
				if ($ps_import_tablename != $ps_target_tablename) {
					$va_path = $o_dm->getPath($ps_import_tablename, $ps_target_tablename);
					$va_path_tables = array_keys($va_path);
					$vs_linking_table = $va_path_tables[1];
				} else {
					$vs_linking_table = $t_target->getSelfRelationTableName();
				}
				
				if ($vs_linking_table) {
					foreach($pa_item['settings']["{$ps_refinery_name}_interstitial"] as $vs_element_code => $va_attrs) {
						if(!is_array($va_attrs)) { 
							$va_attr_vals['_interstitial'][$vs_element_code] = BaseRefinery::parsePlaceholder($va_attrs, $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader));
						} else {
							foreach($va_attrs as $vs_k => $vs_v) {
								$va_attr_vals['_interstitial'][$vs_element_code][$vs_k] = BaseRefinery::parsePlaceholder($vs_v, $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader));
							}
						}
					}
					if (is_array($va_attr_vals['_interstitial']) && sizeof($va_attr_vals['_interstitial'])) { 
						$va_attr_vals['_interstitial_table'] = $vs_linking_table;
					}
				}
			}
		}
		return $va_attr_vals;
	}
	# ---------------------------------------
/**
 * Process relationships on the refinery
 *
 * @param $ps_related_table
 * @param $pa_related_option_list
 * @param array $pa_source_data
 * @param array $pa_item
 * @param int $pn_c
 * @param null $pa_options
 *
 * @return array
 */
	function caProcessRefineryRelated($ps_related_table, $pa_related_option_list, $pa_source_data, $pa_item, $pn_c, $pa_options = null) {
		$o_reader = caGetOption('reader', $pa_options, null);
		$o_log = caGetOption('log', $pa_options, null);
		$o_trans = caGetOption('transaction', $pa_options, null);
		
		global $g_ui_locale_id;
		$va_attr_vals = array();
		
		if (!$pa_related_option_list || !is_array($pa_related_option_list)) {
			return $va_attr_vals;
		}
		
		foreach($pa_related_option_list as $vn_i => $pa_related_options) {
			$vn_id = null;
		
			$va_name = null;
			$vs_name = caGetOption('name', $pa_related_options, null);
		
			$vs_name = BaseRefinery::parsePlaceholder($pa_related_options['name'], $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader, 'returnAsString' => true, 'delimiter' => ' '));
			$vs_idno = BaseRefinery::parsePlaceholder($pa_related_options['idno'], $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader, 'returnAsString' => true, 'delimiter' => ' '));
			$vs_type = BaseRefinery::parsePlaceholder($pa_related_options['type'], $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader, 'returnAsString' => true, 'delimiter' => ' '));
			$vn_parent_id = BaseRefinery::parsePlaceholder($pa_related_options['parent_id'], $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader, 'returnAsString' => true, 'delimiter' => ' '));
		
			if (!$vs_name) { $vs_name = $vs_idno; }

		
			if ($ps_related_table == 'ca_entities') {
				$t_entity = new ca_entities();
				if ($o_trans) { $t_entity->setTransaction($o_trans); }
				if (!$vs_name) {
					$va_name = array();
					foreach($t_entity->getLabelUIFields() as $vs_label_fld) {
						if (!isset($pa_related_options[$vs_label_fld])) { $pa_related_options[$vs_label_fld] = ''; }
						$va_name[$vs_label_fld] = BaseRefinery::parsePlaceholder($pa_related_options[$vs_label_fld], $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader));
					}
				} else {
					$va_name = DataMigrationUtils::splitEntityName($vs_name);
				} 
			
				if (!is_array($va_name) || !$va_name) { 
					if ($o_log) { $o_log->logDebug(_t('[importHelpers:caProcessRefineryRelated] No name specified for table %1', $ps_related_table)); }
					return null;
				}
			} 
		
			if (!$vs_name) { 
				if ($o_log) { $o_log->logDebug(_t('[importHelpers:caProcessRefineryRelated] No name specified for table %1', $ps_related_table)); }
				return null;
			} 
		
			$vs_name = BaseRefinery::parsePlaceholder($vs_name, $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader));
		
			$va_attributes = (isset($pa_related_options['attributes']) && is_array($pa_related_options['attributes'])) ? $pa_related_options['attributes'] : array();
			
			foreach($va_attributes as $vs_element_code => $va_attrs) {
				if(is_array($va_attrs)) {
					foreach($va_attrs as $vs_k => $vs_v) {
						// BaseRefinery::parsePlaceholder may return an array if the input format supports repeated values (as XML does)
						// DataMigrationUtils::getCollectionID(), which ca_data_importers::importDataFromSource() uses to create related collections
						// only supports non-repeating attribute values, so we join any values here and call it a day.
						$va_attributes[$vs_element_code][$vs_k] = BaseRefinery::parsePlaceholder($vs_v, $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader, 'returnAsString' => true, 'delimiter' => ' '));
					}
				} else {
					$va_attributes[$vs_element_code] = array($vs_element_code => BaseRefinery::parsePlaceholder($va_attrs, $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader, 'returnAsString' => true, 'delimiter' => ' ')));
				}
			}
			
			if ($ps_related_table != 'ca_object_lots') {
				$va_attributes['idno'] = $vs_idno;
				$va_attributes['parent_id'] = $vn_parent_id;
			} else {
				$vs_idno_stub = BaseRefinery::parsePlaceholder($pa_related_options['idno_stub'], $pa_source_data, $pa_item, $pn_c, array('reader' => $o_reader, 'returnAsString' => true, 'delimiter' => ' '));	
			}	
			
			$pa_options = array_merge(array('matchOn' => array('idno', 'label'), $pa_options));
			
			switch($ps_related_table) {
				case 'ca_objects':
					$vn_id = DataMigrationUtils::getObjectID($vs_name, $vn_parent_id, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					break;
				case 'ca_object_lots':
					$vn_id = DataMigrationUtils::getObjectLotID($vs_idno_stub, $vs_name, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					break;
				case 'ca_entities':
					$vn_id = DataMigrationUtils::getEntityID($va_name, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					break;
				case 'ca_places':
					$vn_id = DataMigrationUtils::getPlaceID($vs_name, $vn_parent_id, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					break;
				case 'ca_occurrences':
					$vn_id = DataMigrationUtils::getOccurrenceID($vs_name, $vn_parent_id, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					break;
				case 'ca_collections':
					$vn_id = DataMigrationUtils::getCollectionID($vs_name, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					break;
				case 'ca_loans':
					$vn_id = DataMigrationUtils::getLoanID($vs_name, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					break;
				case 'ca_movements':
					$vn_id = DataMigrationUtils::getMovementID($vs_name, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					break;
				case 'ca_list_items':
					if (!($vn_list_id = caGetOption('list_id', $pa_options, null))) {
						if ($o_log) { $o_log->logDebug(_t('[importHelpers:caProcessRefineryRelated] List was not specified')); }
						return null;
					}
					$vn_id = DataMigrationUtils::getListItemID($vn_list_id, $vs_name, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					break;
				case 'ca_storage_locations':
					$vn_id = DataMigrationUtils::getStorageLocationID($vs_name, $vn_parent_id, $vs_type, $g_ui_locale_id, $va_attributes, $pa_options);
					break;
				default:
					if ($o_log) { $o_log->logDebug(_t('[importHelpers:caProcessRefineryRelated] Invalid table %1', $ps_related_table)); }
					return null;
					break;	
			}
		
			if ($vn_id) {
				$va_attr_vals['_related_related'][$ps_related_table][] = array(
					'id' => $vn_id,
					'_relationship_type' => $pa_related_options['relationshipType']
				);
			}
		}
		return $va_attr_vals;
	}
	# ---------------------------------------
	/**
	 * 
	 *
	 * @param string $ps_refinery_name 
	 * 
	 * @return array
	 */
	function caGenericImportSplitter($ps_refinery_name, $ps_item_prefix, $ps_table, $po_refinery_instance, &$pa_destination_data, $pa_group, $pa_item, $pa_source_data, $pa_options) {
		global $g_ui_locale_id;
		
		$po_refinery_instance->setReturnsMultipleValues(true);
		
		$o_dm = Datamodel::load();
		
		$po_refinery_instance->setReturnsMultipleValues(true);
		$o_log = caGetOption('log', $pa_options, null);
		$o_reader = caGetOption('reader', $pa_options, null);
		$o_trans = caGetOption('transaction', $pa_options, null);
		
		$pn_value_index = caGetOption('valueIndex', $pa_options, 0);
		
		// We can probably always use the item destination – using group destination is a vestige of older code and no longer is used
		// but we're leaving it in for now as a fallback it item dest is not set for some reason
		$va_group_dest = (isset($pa_item['destination']) && $pa_item['destination']) ? explode(".", $pa_item['destination']) : explode(".", $pa_group['destination']);
		
		$vs_terminal = array_pop($va_group_dest);
		$vs_dest_table = $va_group_dest[0];
		$va_group_dest[] = $vs_terminal;
		
		$pm_value = (!isset($pa_source_data[$pa_item['source']]) && $o_reader) ? caProcessImportItemSettingsForValue($o_reader->get($pa_item['source'], array('returnAsArray'=> true)), $pa_item['settings']) : $pa_source_data[$pa_item['source']];
		
		if (is_array($pm_value)) {
			if (isset($pm_value[$pn_value_index])) {
				$va_delimited_items = $pm_value[$pn_value_index];	// for input formats that support repeating values
			} else {
				$va_delimited_items = array_shift($va_delimited_items);
			}
		} else {
			$va_delimited_items = array($pm_value);
		}
		
		if (!is_array($va_delimited_items)) { $va_delimited_items = array($va_delimited_items); }
		$va_delimiter = $pa_item['settings']["{$ps_refinery_name}_delimiter"];
		if (!is_array($va_delimiter)) { $va_delimiter = array($va_delimiter); }
							
		if (sizeof($va_delimiter)) {
			foreach($va_delimiter as $vn_index => $vs_delim) {
				if (!trim($vs_delim, "\t ")) { unset($va_delimiter[$vn_index]); continue; }
				$va_delimiter[$vn_index] = preg_quote($vs_delim, "!");
			}
		}
		
		$va_match_on = caGetOption('matchOn', $pa_options, null);
		if (!is_array($va_match_on) && $va_match_on) { 
			$va_match_on = array($va_match_on); 
		} elseif (is_array($va_match_on = $pa_item['settings']["{$ps_refinery_name}_matchOn"])) {
			$pa_options['matchOn'] = $va_match_on;
		}
		
		$pb_dont_create = caGetOption('dontCreate', $pa_options, (bool)$pa_item['settings']["{$ps_refinery_name}_dontCreate"]);
		
		$va_vals = array();
		$vn_c = 0;
		if (!($t_instance = $o_dm->getInstanceByTableName($ps_table, true))) { return array(); }
		if ($o_trans) { $t_instance->setTransaction($o_trans); }
		
		$vs_label_fld = $t_instance->getLabelDisplayField();
		if (
			((sizeof($va_group_dest) == 1) && ($vs_terminal == $ps_table))
			||
			(($vs_terminal != $ps_table) && (sizeof($va_group_dest) > 1))
		) {		
			foreach($va_delimited_items as $vn_x => $vs_delimited_item) {
				$va_items = sizeof($va_delimiter) ? preg_split("!(".join("|", $va_delimiter).")!", $vs_delimited_item) : array($vs_delimited_item);

				foreach($va_items as $vn_i => $vs_item) {
					$va_parents = $pa_item['settings']["{$ps_refinery_name}_parents"];
					
					if (!($vs_item = trim($vs_item))) { 
						if (is_array($va_parents) && (sizeof($va_parents) > 0)) {
							// try to ladder up the parents hierarchy since the base value is blank (see PROV-972)
							$vs_display_field = $t_instance->getLabelDisplayField();
							while(sizeof($va_parents) > 0) {
								$va_p = array_pop($va_parents);
								if ($vs_laddered_val = BaseRefinery::parsePlaceholder($va_p[$vs_display_field], $pa_source_data, $pa_item, $pn_value_index, array('reader' => $o_reader, 'delimiter' => $va_delimiter, 'returnDelimitedValueAt' => $vn_x))) {
									if ($o_log) { $o_log->logDebug(_t('[{$ps_refinery_name}] Used parent value %1 because the mapped value was blank', $vs_item)); }
									$vs_item = $vs_laddered_val;
									break;
								}
							}
						}
						if (!$vs_item) { 
							continue; 
						}
					}
					if (is_array($va_skip_values = $pa_item['settings']["{$ps_refinery_name}_skipIfValue"]) && in_array($vs_item, $va_skip_values)) {
						if ($o_log) { $o_log->logDebug(_t('[{$ps_refinery_name}] Skipped %1 because it was in the skipIfValue list', $vs_item)); }
						continue;
					}
			
					// Set label
					$va_val = array();
				
					// Set value as hierarchy
					if ($va_hierarchy_setting = $pa_item['settings']["{$ps_refinery_name}_hierarchy"]) {
						$va_attr_vals = $va_val = caProcessRefineryParents($ps_refinery_name, $ps_table, $va_hierarchy_setting, $pa_source_data, $pa_item, $pn_value_index, array_merge($pa_options, array('hierarchyMode' => true)));
						$vs_item = $va_val['_preferred_label'];
					} else {
		
						// Set type
						if (
							($vs_type_opt = $pa_item['settings']["{$ps_refinery_name}_{$ps_item_prefix}Type"])
						) {
							$va_val['_type'] = BaseRefinery::parsePlaceholder($vs_type_opt, $pa_source_data, $pa_item, $pn_value_index, array('reader' => $o_reader));
						}
			
						if((!isset($va_val['_type']) || !$va_val['_type']) && ($vs_type_opt = $pa_item['settings']["{$ps_refinery_name}_{$ps_item_prefix}TypeDefault"])) {
							if (!($va_val['_type'] = BaseRefinery::parsePlaceholder($vs_type_opt, $pa_source_data, $pa_item, $pn_value_index, array('reader' => $o_reader, 'delimiter' => $va_delimiter, 'returnDelimitedValueAt' => $vn_x)))) {
								$va_val['_type'] = BaseRefinery::parsePlaceholder($vs_type_opt, $pa_source_data, $pa_item, $pn_value_index, array('reader' => $o_reader));
							}
						}
				
						// Set lot_status
						if (
							($vs_type_opt = $pa_item['settings']["{$ps_refinery_name}_{$ps_item_prefix}Status"])
						) {
							$va_val['_status'] = BaseRefinery::parsePlaceholder($vs_type_opt, $pa_source_data, $pa_item, $pn_value_index, array('reader' => $o_reader));
						}
						if((!isset($va_val['_status']) || !$va_val['_status']) && ($vs_type_opt = $pa_item['settings']["{$ps_refinery_name}_{$ps_item_prefix}StatusDefault"])) {
							$va_val['_status'] = BaseRefinery::parsePlaceholder($vs_type_opt, $pa_source_data, $pa_item, $pn_value_index, array('reader' => $o_reader));
						}
			
						if ((!isset($va_val['_type']) || !$va_val['_type']) && $o_log) {
							$o_log->logWarn(_t("[{$ps_refinery_name}] No %2 type is set for %2 %1", $vs_item, $ps_item_prefix));
						}
				
						//
						// Storage location specific options
						//
						if (($ps_refinery_name == 'storageLocationSplitter') && ($va_hier_delimiter = $pa_item['settings']['storageLocationSplitter_hierarchicalDelimiter'])) {
							if (!is_array($va_hier_delimiter)) { $va_hier_delimiter = array($va_hier_delimiter); }
							
							if (sizeof($va_hier_delimiter)) {
								foreach($va_hier_delimiter as $vn_index => $vs_delim) {
									if (!trim($vs_delim, "\t ")) { unset($va_hier_delimiter[$vn_index]); continue; }
									$va_hier_delimiter[$vn_index] = preg_quote($vs_delim, "!");
								}
							}
							
							$va_location_hier = preg_split("!(".join("|", $va_hier_delimiter).")!", $vs_item);
							
							if (sizeof($va_location_hier) > 1) {
					
								$vn_location_id = null;
				
								if (!is_array($va_types = $pa_item['settings']['storageLocationSplitter_hierarchicalStorageLocationTypes'])) {
									$va_types = array();
								}
						
								$vs_item = array_pop($va_location_hier);
								if (!($va_val['_type'] = array_pop($va_types))) {
									$va_val['_type'] = $pa_item['settings']['storageLocationSplitter_storageLocationTypeDefault'];
								}
					
								foreach($va_location_hier as $vn_i => $vs_parent) {
									if (sizeof($va_types) > 0)  { 
										$vs_type = array_shift($va_types); 
									} else { 
										if (!($vs_type = $pa_item['settings']['storageLocationSplitter_storageLocationType'])) {
											$vs_type = $pa_item['settings']['storageLocationSplitter_storageLocationTypeDefault'];
										}
									}
									if (!$vs_type) { break; }
									$vn_location_id = DataMigrationUtils::getStorageLocationID($vs_parent, $vn_location_id, $vs_type, $g_ui_locale_id, array('idno' => $vs_parent, 'parent_id' => $vn_location_id), $pa_options);
								}
								$va_val['parent_id'] = $va_val['_parent_id'] = $vn_location_id;
							}
						} else {
							// Set parents
							if ($va_parents) {
								$va_val['parent_id'] = $va_val['_parent_id'] = caProcessRefineryParents($ps_refinery_name, $ps_table, $va_parents, $pa_source_data, $pa_item, $pn_value_index, $pa_options);
							}
				
							if (isset($pa_options['defaultParentID']) && (!isset($va_val['parent_id']) || !$va_val['parent_id'])) {
								$va_val['parent_id'] = $va_val['_parent_id'] = $pa_options['defaultParentID'];
							}
						}
				
						if(isset($pa_options['hierarchyID']) && $pa_options['hierarchyID'] && ($vs_hier_id_fld = $t_instance->getProperty('HIERARCHY_ID_FLD'))) {
							$va_val[$vs_hier_id_fld] = $pa_options['hierarchyID'];
						}
		
						// Set attributes
						if (is_array($va_attr_vals = caProcessRefineryAttributes($pa_item['settings']["{$ps_refinery_name}_attributes"], $pa_source_data, $pa_item, $vn_i, array('log' => $o_log, 'reader' => $o_reader)))) {
							$va_val = array_merge($va_val, $va_attr_vals);
						}
			
						// Set interstitials
						if (isset($pa_options['mapping']) && is_array($va_attr_vals = caProcessInterstitialAttributes($ps_refinery_name, $pa_options['mapping']->get('table_num'), $ps_table, $pa_source_data, $pa_item, $vn_i, array('log' => $o_log, 'reader' => $o_reader)))) {
							$va_val = array_merge($va_val, $va_attr_vals);
						}

						// Set relationships on the related table
						caProcessRefineryRelatedMultiple($po_refinery_instance, $pa_item, $pa_source_data, $vn_i, $o_log, $o_reader, $va_val, $va_attr_vals);

						// Set nonpreferred labels
						if (is_array($va_non_preferred_labels = $pa_item['settings']["{$ps_refinery_name}_nonPreferredLabels"])) {
							$pa_options['nonPreferredLabels'] = array();
							foreach($va_non_preferred_labels as $va_label) {
								foreach($va_label as $vs_k => $vs_v) {
									$va_label[$vs_k] = BaseRefinery::parsePlaceholder($vs_v, $pa_source_data, $pa_item, $pn_value_index, array('reader' => $o_reader, 'returnAsString' => true, 'delimiter' => ' '));
								}
								$pa_options['nonPreferredLabels'][] = $va_label;
							}
						}
					}
				
					if (
						(($vs_dest_table != $ps_table) && (sizeof($va_group_dest) > 1))
					) {	
				
						$vs_item = BaseRefinery::parsePlaceholder($vs_item, $pa_source_data, $pa_item, $pn_value_index, array('reader' => $o_reader, 'returnAsString' => true, 'delimiter' => ' '));
						if(!is_array($va_attr_vals)) { $va_attr_vals = array(); }
						$va_attr_vals_with_parent = array_merge($va_attr_vals, array('parent_id' => $va_val['parent_id'] ? $va_val['parent_id'] : $va_val['_parent_id']));

						$pa_options = array('matchOn' => array('idno', 'label')) +  $pa_options;
						
						switch($ps_table) {
							case 'ca_objects':
								$vn_item_id = DataMigrationUtils::getObjectID($vs_item, $va_val['parent_id'], $va_val['_type'], $g_ui_locale_id, $va_attr_vals_with_parent, $pa_options);
								break;
							case 'ca_object_lots':
								if (isset($va_val['_status'])) {
									$va_attr_vals['lot_status_id'] = $va_val['_status'];
								}
								unset($va_val['_status']);
								$vn_item_id = DataMigrationUtils::getObjectLotID($vs_item, $vs_item, $va_val['_type'], $g_ui_locale_id, $va_attr_vals, $pa_options);
								break;
							case 'ca_entities':
								$vn_item_id = DataMigrationUtils::getEntityID(DataMigrationUtils::splitEntityName($vs_item), $va_val['_type'], $g_ui_locale_id, $va_attr_vals_with_parent, $pa_options);
								break;
							case 'ca_places':
								$vn_item_id = DataMigrationUtils::getPlaceID($vs_item, $va_val['parent_id'], $va_val['_type'], $g_ui_locale_id, $va_attr_vals_with_parent, $pa_options);
								break;
							case 'ca_occurrences':
								$vn_item_id = DataMigrationUtils::getOccurrenceID($vs_item, $va_val['parent_id'], $va_val['_type'], $g_ui_locale_id, $va_attr_vals_with_parent, $pa_options);
								break;
							case 'ca_collections':
								$vn_item_id = DataMigrationUtils::getCollectionID($vs_item, $va_val['_type'], $g_ui_locale_id, $va_attr_vals_with_parent, $pa_options);
								break;
							case 'ca_loans':
								$vn_item_id = DataMigrationUtils::getLoanID($vs_item, $va_val['_type'], $g_ui_locale_id, $va_attr_vals_with_parent, $pa_options);
								break;
							case 'ca_movements':
								$vn_item_id = DataMigrationUtils::getMovementID($vs_item, $va_val['_type'], $g_ui_locale_id, $va_attr_vals_with_parent, $pa_options);
								break;
							case 'ca_list_items':
								if (!$pa_options['list_id']) {
									if ($o_log) { $o_log->logDebug(_t('[importHelpers:caGenericImportSplitter] List was not specified')); }
									continue(2);
								}
								$va_attr_vals_with_parent['is_enabled'] = 1;
								$vn_item_id = DataMigrationUtils::getListItemID($pa_options['list_id'], $vs_item, $va_val['_type'], $g_ui_locale_id, $va_attr_vals_with_parent, $pa_options);
								break;
							case 'ca_storage_locations':
								$vn_item_id = DataMigrationUtils::getStorageLocationID($vs_item, $va_val['parent_id'], $va_val['_type'], $g_ui_locale_id, $va_attr_vals_with_parent, $pa_options);
								break;
							case 'ca_object_representations':
								if ($o_log) { $o_log->logDebug(_t('[importHelpers:caGenericImportSplitter] Only media paths can be mapped for object representations.')); }
								continue(2);
							default:
								if ($o_log) { $o_log->logDebug(_t('[importHelpers:caGenericImportSplitter] Invalid table %1', $ps_table)); }
								continue(2);
								break;	
						}
					
						if ($vn_item_id) {
							//$po_refinery_instance->setReturnsMultipleValues(false);
							//return $vn_item_id;
							$va_vals[][$vs_terminal] = $vn_item_id;
							continue;
						} else {
							if ($o_log && !$pb_dont_create) { $o_log->logError(_t("[{$ps_refinery_name}Refinery] Could not add %2 %1", $vs_item, $ps_item_prefix)); }
						}
					} elseif ((sizeof($va_group_dest) == 1) && ($vs_terminal == $ps_table)) {
						// Set relationship type
						if (
							($vs_rel_type_opt = $pa_item['settings']["{$ps_refinery_name}_relationshipType"])
						) {
							$va_val['_relationship_type'] = BaseRefinery::parsePlaceholder($vs_rel_type_opt, $pa_source_data, $pa_item, $pn_value_index, array('reader' => $o_reader));
						}
			
						if (
							(!isset($va_val['_relationship_type']) || !$va_val['_relationship_type']) 
							&& 
							($vs_rel_type_opt = $pa_item['settings']["{$ps_refinery_name}_relationshipTypeDefault"])	
						) {
							if (!($va_val['_relationship_type'] = BaseRefinery::parsePlaceholder($vs_rel_type_opt, $pa_source_data, $pa_item, $pn_value_index, array('reader' => $o_reader, 'delimiter' => $va_delimiter, 'returnDelimitedValueAt' => $vn_x)))) {
								$va_val['_relationship_type'] = BaseRefinery::parsePlaceholder($vs_rel_type_opt, $pa_source_data, $pa_item, $pn_value_index, array('reader' => $o_reader));
							}
						}

						if ((!isset($va_val['_relationship_type']) || !$va_val['_relationship_type']) && $o_log) {
							$o_log->logWarn(_t("[{$ps_refinery_name}Refinery] No relationship type is set for %2 %1", $vs_item, $ps_item_prefix));
						}
	
						switch($ps_table) {
							case 'ca_entities':
								$va_val['preferred_labels'] = DataMigrationUtils::splitEntityName($vs_item);
								break;
							case 'ca_list_items':
								$va_val['preferred_labels'] = array('name_singular' => str_replace("_", " ", $vs_item), 'name_plural' => str_replace("_", " ", $vs_item));
								$va_val['_list'] = $pa_options['list_id'];
								break;
							case 'ca_storage_locations':
							case 'ca_movements':
							case 'ca_loans':
							case 'ca_collections':
							case 'ca_occurrences':
							case 'ca_places':
							case 'ca_objects':
								$va_val['preferred_labels'] = array('name' => $vs_item);
								break;
							case 'ca_object_lots':
								$va_val['preferred_labels'] = array('name' => $vs_item);
								
								if (isset($va_val['_status'])) {
									$va_val['lot_status_id'] = $va_val['_status'];
								}
								unset($va_val['_status']);
								break;
							case 'ca_object_representations':
								if (!($vs_batch_media_directory = $t_instance->getAppConfig()->get('batch_media_import_root_directory'))) { break; }
							
								if(!isset($va_val['preferred_labels'])) { $va_val['preferred_labels'] = array('name' => $vs_item); }
							
								if (isset($pa_item['settings']['objectRepresentationSplitter_mediaPrefix']) && $pa_item['settings']['objectRepresentationSplitter_mediaPrefix'] && isset($va_val['media']['media']) && ($va_val['media']['media'])) {
									$va_val['media']['media'] = $vs_batch_media_directory.'/'.$pa_item['settings']['objectRepresentationSplitter_mediaPrefix'].'/'.$va_val['media']['media'];
								}
								break;
							default:
								if ($o_log) { $o_log->logDebug(_t('[importHelpers:caGenericImportSplitter] Invalid table %1', $ps_table)); }
								continue(2);
								break;	
						}
						if (isset($pa_options['nonPreferredLabels']) && is_array($pa_options['nonPreferredLabels'])) {
							$va_val['nonpreferred_labels'] = $pa_options['nonPreferredLabels'];
						}
					} elseif ((sizeof($va_group_dest) == 2) && ($vs_terminal == 'preferred_labels')) {
					
						switch($ps_table) {
							case 'ca_entities':
								$va_val = DataMigrationUtils::splitEntityName($vs_item);
								break;
							case 'ca_list_items':
								$va_val = array('name_singular' => $vs_item, 'name_plural' => $vs_item);
								break;
							case 'ca_storage_locations':
							case 'ca_movements':
							case 'ca_loans':
							case 'ca_collections':
							case 'ca_occurrences':
							case 'ca_places':
							case 'ca_objects':
								$va_val = array('name' => $vs_item);
								break;
							case 'ca_object_lots':
								$va_val = array('name' => $vs_item);
								break;
							case 'ca_object_representations':
								if ($o_log) { $o_log->logDebug(_t('[importHelpers:caGenericImportSplitter] Cannot map preferred labels to object representations. Only media paths can be mapped.')); }
								continue(2);
							default:
								if ($o_log) { $o_log->logDebug(_t('[importHelpers:caGenericImportSplitter] Invalid table %1', $ps_table)); }
								continue(2);
								break;	
						}
					} else {
						if ($o_log) { $o_log->logError(_t("[{$ps_refinery_name}Refinery] Could not add %2 %1: cannot map %3 using %1", $vs_item, $ps_item_prefix, join(".", $va_group_dest))); }
					}
					$va_val['_matchOn'] = $va_match_on;
					if ($pb_dont_create) { $va_val['_dontCreate'] = 1; }
					$va_vals[] = $va_val;
					$vn_c++;
				}
			}
		} else {
			if ($o_log) { $o_log->logError(_t("[{$ps_refinery_name}Refinery] Cannot map %1 using this refinery", $pa_group['destination'])); }
			return array();
		}
		
		return $va_vals;
	}
# ---------------------------------------
/**
 * Uses caProcessRefineryRelated to set a list of relationships on related records. Also takes legacy relatedEntities into account
 * @param $po_refinery_instance BaseRefinery
 * @param $pa_item array
 * @param $pa_source_data array
 * @param $pn_value_index int
 * @param $o_log KLogger
 * @param $o_reader BaseDataReader
 * @param $va_val array
 * @param $va_attr_vals array
 */
function caProcessRefineryRelatedMultiple($po_refinery_instance, &$pa_item, $pa_source_data, $pn_value_index, $o_log, $o_reader, &$va_val, &$va_attr_vals) {
	$vs_relationship_settings_key = $po_refinery_instance->getName() . '_relationships';
	// Set relatedEntities to support legacy mappings
	if (is_array($va_related_entities_settings = $pa_item['settings'][$po_refinery_instance->getName() . '_relatedEntities'])) {
		$pa_item['settings'][] = is_array($pa_item['settings'][$vs_relationship_settings_key]) ? $pa_item['settings'][$vs_relationship_settings_key] : array();
		foreach ($va_related_entities_settings as $va_related_entity_setting) {
			$va_related_entity_setting['relatedTable'] = isset($va_related_entity_setting['relatedTable']) ? $va_related_entity_setting['relatedTable'] : 'ca_entities';
			$pa_item['settings'][$vs_relationship_settings_key][] = $va_related_entity_setting;
		}
	}
	// Set relationships
	if (is_array($va_relationships = $pa_item['settings'][$vs_relationship_settings_key])) {
		foreach ($va_relationships as $va_relationship_settings) {
			if ($vs_table_name = caGetOption('relatedTable', $va_relationship_settings)) {
				if (is_array($va_attr_vals = caProcessRefineryRelated($vs_table_name, array($va_relationship_settings), $pa_source_data, $pa_item, $pn_value_index, array('log' => $o_log, 'reader' => $o_reader)))) {
					$va_val = array_merge($va_val, $va_attr_vals);
				}
			}
		}
	}
}

# ---------------------------------------
	/**
	 * Apply item settings to value; used by refineries to apply regular expressions to values get()'ed from reader class
	 *
	 * @param mixed $pm_value
	 * @param array $pa_item_settings
	 *
	 * @return mixed
	 */
	function caProcessImportItemSettingsForValue($pm_value, $pa_item_settings) {
		if (isset($pa_item_settings['applyRegularExpressions']) && is_array($pa_item_settings['applyRegularExpressions'])) {
			if(is_array($pa_item_settings['applyRegularExpressions'])) {
				if (is_array($pm_value)) {
					foreach($pm_value as $vn_i => $vs_value) {
						foreach($pa_item_settings['applyRegularExpressions'] as $vn_c => $va_regex) {
							if (!strlen($va_regex['match'])) { continue; }
							$vs_value = preg_replace("!".str_replace("!", "\\!", $va_regex['match'])."!".((isset($va_regex['caseSensitive']) && (bool)$va_regex['caseSensitive']) ? '' : 'i'), $va_regex['replaceWith'], $vs_value);
						}
						$pm_value[$vn_i] = $vs_value;
					}
				} else {
					foreach($pa_item_settings['applyRegularExpressions'] as $vn_i => $va_regex) {
						if (!strlen($va_regex['match'])) { continue; }
						$pm_value = preg_replace("!".str_replace("!", "\\!", $va_regex['match'])."!".((isset($va_regex['caseSensitive']) && (bool)$va_regex['caseSensitive']) ? '' : 'i'), $va_regex['replaceWith'], $pm_value);
					}
				}
			}
		}
		return $pm_value;
	}
	# ---------------------------------------
	/**
	 * Returns array of valid importer logging levels. Keys of array are display names for levels, values are KLogger integer log-level constants
	 *
	 * @return array
	 */
	function caGetLogLevels() {
		return array(
			_t('Errors') => KLogger::ERR,
			_t('Warnings') => KLogger::WARN,
			_t('Alerts') => KLogger::NOTICE,
			_t('Infomational messages') => KLogger::INFO,
			_t('Debugging messages') => KLogger::DEBUG
		);
	}
	# ---------------------------------------
?>