<?php
/**
 * @copyright	Jentla, http://www.jentla.com Copyright (c) 2021 Jentla Pty. Ltd.
 * @license		GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

jimport('jentla.rest');
JLoader::import('ourtradie', JPATH_SITE . '/components/com_jentlacontent/models');
JModel::addIncludePath(JPATH_SITE . '/components/com_jentlacontent/models', 'JentlaContentModel');

define("NBS_PAST", -1);
define("NBS_DRAFT", 0);
define("NBS_UNSIGNED", 1);
define("NBS_INCOMPLETE", 2);
define("NBS_COMPLETED", 3);
define("NBS_PUNT_FAILED", -2);
define("NBS_REVIEW", -3);
define("NBS_DISPUTED", -4);

class JentlaContentModelNbsignup extends JentlacontentModelOurtradie
{
	protected $_item = null;

	protected $_nbtype = null;

	protected $_layout = 'default';

	public function __construct($config = array())
	{
		parent::__construct($config);
		// Append layout on context for State
		if ($layout = JRequest::getCmd('layout'))
			$this->_layout = $layout;
	}

	protected function populateState($ordering = null, $direction = null)
	{
		// Initiliase variables.
		$app = JFactory::getApplication();

		$type_id = JRequest::getInt('type_id');
		$this->setState('nbsignup.type_id', $type_id);

		$pk	= JRequest::getInt('id');
		$this->setState('nbsignup.id', $pk);

		$step = JRequest::getCmd('step');
		$this->setState('nbsignup.step', $step);

		$return = JRequest::getVar('return', null, 'default', 'base64');
		$this->setState('return_page', urldecode(base64_decode($return)));

		// Load the parameters. Merge Global and Menu Item params into new object
		$params = $app->getParams();
		$menuParams = new JRegistry;
		if ($menu = $app->getMenu()->getActive()) {
			$menuParams->loadString($menu->params);
		}
		$mergedParams = clone $menuParams;
		$mergedParams->merge($params);

		$this->setState('params', $mergedParams);
	}

	public function canAccess($pk)
	{
		if (empty($pk)) {
			$this->setError('Empty references not allowed to load');
			return false;
		}

		$user = JFactory::getUser();
		if (!$user->get('id')) {
			$this->setError('Please login to access this resource');
			return false;
		}

		if (!$item = $this->getItem($pk)) {
			if (!$error = $this->getError())
				$error = 'Unable to load your signup details';
			$this->setError($error);
			return false;
		}

		if (JentlaContentHelperOurTradie::isPMGroup()) {
			if (!$agent = JentlacontentHelperOurTradie::getAgent()) {
				$this->setError('Unable to load your agency details');
				return false;
			}

			if (!$agent_id = (int)$agent->get('id')) {
				$this->setError('Unable to find your agency reference');
				return false;
			}

			if ($item->property_agent != $agent_id) {
				$this->setError('You don\'t have rights to access this sign-up');
				return false;
			}
		}
		else {
			$owners = explode(',', $item->property_owner);
			if (!in_array($user->get('id'), $owners)) {
				$this->setError('You don\'t have rights to access this sign-up');
				return false;
			}
		}

		return true;
	}

	public function getLandlords($pks, $property = array())
	{
		if (is_array($pks)) {
			$post_data = $pks;
			if ($post_pks = JArrayHelper::getValue($post_data, 'pks'))
				$pks = $post_pks;
			$nbs_id = JArrayHelper::getValue($post_data, 'nbs_id');
			$property = JArrayHelper::getValue($post_data, 'property');
		}
		if (!is_array($property))
			$property = (array)$property;

		if (empty($pks)) {
			$this->setError('Empty ll references not allowed to load');
			return false;
		}

		$db = $this->getDbo();
		$query	= $db->getQuery(true);
		$query->select('ju.id, ju.name, ju.lastname, ju.middlename, ju.fullname, ju.email, ju.method_contact, ju.contact_type, ju.username');
		$query->select('ju.salutation, ju.cc_email, ju.phone_no, ju.mobile, ju.phone, ju.abn, ju.id AS user_id, ju.unique_id');
		$query->from('#__jentlausers AS ju');
		$pks = is_array($pks) ? $pks : explode(',', $pks);
		$query->where('ju.id IN ('. implode(',', $pks) . ')');

		$query->select('ow.title, ow.method_contact, ow.contact_instructions, ow.address, ow.address2, ow.suburb, ow.state, ow.zipcode, ow.gst_registered');
		$query->select('ow.owner_fax, ow.emergency_type, ow.emergency_name, ow.emergency_email, ow.emergency_phone, ow.emergency_mobile');
		$query->join('INNER', '#__owner AS ow ON ow.id = ju.id');

		// Setup the query
		$db->setQuery($query);
		$landlords = $db->loadAssocList();
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		foreach ($landlords as &$landlord)
		{
			$landlord_id = JArrayHelper::getValue($landlord, 'id');
			$ourtradieModel = JModel::getInstance('Ourtradie', 'JentlaContentModel', array('ignore_request' => true));
			$landlord['isConnected'] = $ourtradieModel->isConnectedLL($landlord_id, $property) ? 1: 0;
		}

		if (isset($nbs_id) && !empty($nbs_id)) {
			$data = $this->getItem($nbs_id);
			$landlords = $this->formatLandlords($landlords, $data);
		}

		return $landlords;
	}

	protected function hasItem($data)
	{
		if (!$property_id = JArrayHelper::getValue($data, 'property_id')) {
			$this->setError('Empty property reference not allowed to load property');
			return false;
		}

		$db		= $this->getDbo();
		$query	= $db->getQuery(true);
		$query->select('id, business_type');
		$query->from('#__nbs');
		$query->where('(state IN (0, 1) OR (past_id=-1 AND state>-1))');
		$query->where('property_id IN ('. $property_id . ')');
		if ($pk = JArrayHelper::getValue($data, 'id'))
			$query->where('id NOT IN (' . $pk . ')');

		// Setup the query
		$db->setQuery($query);
		$hasItem = $db->loadObject();
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		return $hasItem;
	}
	public function flagDuplicates($data)
	{
		if (!$propertyIds = JArrayHelper::getValue($data, 'propertyIds')) {
			$this->setError('Property List Empty');
			return false;
		}
		$db = $this->getDbo();
		$query = $db->getQuery(true);
		$query->select('id,property_address1,unique_id');
		$query->from('#__property');
		$query->where($db->quoteName('id') . ' IN (' . implode(',', $propertyIds) . ')');
		$db->setQuery($query);
		$properties = $db->loadAssocList();
		$tempProperty = array();
		$duplicateCount = 1;
		foreach ($properties as $property) {
			$property['property_address1'] .= ' -duplicate' . $duplicateCount;
			$property['unique_id'] = 'z' . $property['unique_id'];
			$tempProperty[] = array(
				'id' => $property['id'],
				'property_address1' => $property['property_address1'],
				'unique_id' => $property['unique_id']
			);
			$duplicateCount++;  
		}
		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		$table_data = array (
			'key' => 'id',
			'table' => 'property',
			'data' => $tempProperty
		);
		if (!$result = $actionfieldModel->saveTable($table_data)) {
			$this->setError($actionfieldModel->getError());
			return false;
		}
		return $duplicateCount;
	}
	public function findDuplicateProperties($data)
	{
		if ($searchId = JArrayHelper::getValue($data, 'property_id'))
			$data = (array) $this->getProperty(array('property_id'=>$searchId));

		if (!$address = JArrayHelper::getValue($data,'property_address1')) {
			$this->setError('Property address Empty while retrieving');
			return false;
		}
		if (!$code = JArrayHelper::getValue($data,'property_postcode')) {
			$this->setError('Property postcode Empty while retrieving');
			return false;
		}
		$db = $this->getDbo();
		$query = $db->getQuery(true);
		$query->select('p.id, p.property_address1, p.property_address2, p.property_suburb, p.property_postcode, p.property_state');
		$query->select('p.inactive, p.property_tenant, p.property_manager, p.ownership');
		$query->select('pm.fullname AS name, tl.lease_start, tl.lease_end');
		$query->from('#__property AS p');
		$query->join('LEFT', '#__tenant_lease AS tl ON tl.property_id = p.id AND FIND_IN_SET(tl.tenant_id, p.property_tenant)');
		$query->join('INNER', '#__jentlausers AS pm ON pm.id = p.property_manager');
		$query->where($db->quoteName('p.property_address1') . ' = ' . $db->quote($address));
		$query->where($db->quoteName('p.property_postcode') . ' = ' . $db->quote($code));
		if ($agent = JentlacontentHelperOurTradie::getAgent()) {
			$filter_agency_id = $agent->get('id');
			$query->where('p.property_agent=' . (int)$filter_agency_id);
		}
		$query->group('p.id');
		$db->setQuery($query);
		$properties = $db->loadObjectList();
		// Check for a database error.
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}
		foreach ($properties as &$property) {
			if (empty($property->lease_start) || $property->lease_start == '0000-00-00') {
				$property->lease_start = 'No Lease Start';
			} else {
				$property->lease_start = JFactory::getDate($property->lease_start)->format('d/m/Y');
			}
			if (empty($property->lease_end) || $property->lease_end == '0000-00-00') {
				$property->lease_end = 'No Lease End';
			} else {
				$property->lease_end = JFactory::getDate($property->lease_end)->format('d/m/Y');
			}
			if ($property->lease_start == 'No Lease Start' && $property->lease_end == 'No Lease End') {
				$property->lease_display = 'NIL';
			} else {
				$property->lease_display = $property->lease_start . ' - ' . $property->lease_end;
			}
		}
		return $properties;
	}
	public function formattedProperty($data)
	{
		if ($post_in_data = JArrayHelper::getValue($data, 'data'))
			$data = $post_in_data;

		if (!$property_id = JArrayHelper::getValue($data, 'property_id')) {
			$this->setError('Empty property reference not allowed to load property');
			return false;
		}

		if (!$property = $this->getProperty(array ('property_id' => $property_id))) {
			if (!$error = $this->getError())
				$this->setError('Empty property details not allowed to load');
			return false;
		}

		if (!empty($property->sales_agent))
			$property->sales_agent = !is_array($property->sales_agent) ? explode(',', $property->sales_agent) : $property->sales_agent;

		return $property;
	}

	public function getProperty($data)
	{
		if ($post_in_data = JArrayHelper::getValue($data, 'data'))
			$data = $post_in_data;

		if (!$property_id = JArrayHelper::getValue($data, 'property_id')) {
			$this->setError('Empty property reference not allowed to load property');
			return false;
		}

		$db		= $this->getDbo();
		$query	= $db->getQuery(true);
		$query->select('p.id, p.property_address1, p.property_address2, p.property_suburb, p.property_postcode, p.property_state');
		$query->select('p.property_agent, p.property_owner, p.property_manager, p.bdm_ll_manager, p.sales_agent, p.property_type, p.unique_id, p.disbursement_type, p.agency_profile');
		$query->select('p.rent, p.ppty_bedrooms, p.ppty_bathrooms, p.ppty_caraccomm, p.ppty_pets, p.default_payment_system');
		$query->select('p.ppty_storage, p.ppty_furnished, p.ownership, p.smokecode, p.gatecode, p.ppty_pets, p.property_picture');
		$query->select('p.plannum, p.planname, p.planaddr1, p.planaddr2, p.planaddr3, p.property_lot, p.property_id, p.common_property, p.management_start,p.management_expiry, DATE(p.property_expiry) AS property_expiry, p.strata_property_type, p.ctp_water, p.property_rental_type, p.inactive, p.key_number, p.let_only, p.management_review, p.property_tenant, p.last_increase,p.disburse_date,p.disburse_day, p.disburse_frequency');
		$query->from('#__property AS p');
		$query->where('p.id IN ('. $property_id . ')');

		$query->select('pm.id AS payment_map');
		$query->join('LEFT', '#__payment_property_map AS pm ON pm.property_id=p.id AND pm.agency_id=p.property_agent AND pm.pending=0');

		// Setup the query
		$db->setQuery($query);
		$property = $db->loadObject();
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		return $property;
	}

	public function getCurrentProperty($data)
	{
		if ($post_in_data = JArrayHelper::getValue($data, 'data'))
			$data = $post_in_data;

		if (!$property = $this->formattedProperty($data))
			return false;

		$action = JArrayHelper::getValue($data ,'action');
		if ($action == 'current_owner') {
			if (!$property->landlords = $this->getLandlords($property->property_owner, $property)) {
				if (!$this->getError())
					$this->setError('Unable to load the current landlords');
				return false;
			}
		}

		$current_signup = '';
		if ($current_item = $this->getCurrentItem($property->id))
		{
			$current_owner = $current_item->property_owner;
			if (!is_array($current_owner))
				$current_owner = explode(',', $current_owner);
			$property_owner = $property->property_owner;
			if (!is_array($property_owner))
				$property_owner = explode(',', $property_owner);
			if (array_intersect($property_owner, $current_owner))
				$current_signup = $current_item;
		}

		$future_signup = $this->getFuturetem($property->id);
		$property_data = array (
			'property' => $property,
			'current_signup' => $current_signup,
			'future_signup' => $future_signup
		);

		return $property_data;
	}

	public function getFuturetem($property_id)
	{
		if (!$property_id) {
			$this->setError('Empty property_id not allowed to load current nbs');
			return false;
		}

		$db		= $this->_db;
		$query	= $db->getQuery(true);
		$query->select('nbs.*, nbs.id as signup_id');
		$query->from('#__nbs AS nbs');
		$query->where('nbs.property_id IN ('. (int)$property_id . ')');
		$query->where('(nbs.state IN (' . implode(',', array (NBS_DRAFT, NBS_UNSIGNED)) . ') OR (nbs.state IN (' . implode(',', array (NBS_INCOMPLETE, NBS_COMPLETED)) . ') AND nbs.past_id = -1))');
		$query->select('p.management_expiry');
		$query->join('INNER', '#__property AS p ON p.id=nbs.property_id');
		$db->setQuery((string)$query);
		$item = $db->loadObject();

		// Check for a database error.
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		return $item;
	}

	public function getItem($pk = null)
	{
		$pk	= (!empty($pk)) ? $pk : (int) $this->getState('nbsignup.id');
		if (!$pk) {
			return (object)array (
				'business_type' => $this->getState('nbsignup.type_id')
			);
		}

		$db		= $this->getDbo();
		$query	= $db->getQuery(true);
		$query->select('nbs.*');
		$query->from('#__nbs AS nbs');
		$query->where('nbs.id IN ('. $pk . ')');
		$query->select('p.property_agent, p.property_manager, p.inactive as property_inactive');
		$query->join('INNER', '#__property AS p ON p.id=nbs.property_id');

		// Setup the query
		$db->setQuery($query);
		$item = $db->loadObject();

		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		if (empty($item->property_id)) {
			$this->setError('Unable to find property_id from nbs');
			return false;
		}

		return $item;
	}

	public function getDetailItem($pk = null, $for_user_group = null)
	{
		Utilities::addLogs(print_r($pk,true),'pkp24',true);
		$data = $this->getItem($pk);
		Utilities::addLogs(print_r($data,true),'getDetailItem24',true);
		$prop_id = $data->property_id;
		$data->last_modified_time = $data->modified;
		if ($this->getError())
			return false;

		if ($type_id = $this->getState('nbsignup.type_id'))
			$data->business_type = $type_id;

		$user = JFactory::getUser();
		$data->logged_user_id = $user->id;
		$system_type_state  = 'SELECT st.property_state FROM #__nbs_system_types AS st';
		$system_type_state .= ' INNER JOIN #__nbs_user_types as ut on ut.system_type = st.id';
		$system_type_state .= ' WHERE ut.id =' .$data->business_type;
		if(!$data->system_type_state = Utilities::getSqlResult($system_type_state, false)) {
			if ($error = Utilities::getError()) {
				$this->setError($error);
				return false;
			}
		}

		if (!$nbtype = $this->getBusinessType($data->business_type)) {
			if (!$error = $this->getError())
				$this->setError('Unable to load business type');
			return false;
		}
		$data->nbtype_params = $nbtype->params;
		$data->nbtype_property_state = $nbtype->property_state;
		$data->nb_prop_type = JArrayHelper::getValue($nbtype->params, 'property_type');
		$data->nb_agency_prf = JArrayHelper::getValue($nbtype->params, 'agency_profile', "0");
		$data->nb_rental_type = JArrayHelper::getValue($nbtype->params, 'property_rental_type');
		$data->nb_disbursement_type = JArrayHelper::getValue($nbtype->params, 'disbursement_type', 4);
		$data->nb_pay_repair_maintenance_account = JarrayHelper::getValue($nbtype->params, 'pay_repair_maintenance_account');
		$data->nb_disburse_date = JArrayHelper::getValue($nbtype->params,'disburse_date');
		$data->nb_disburse_day = JArrayHelper::getValue($nbtype->params,'disburse_day');
		$data->nb_check_id_documents = JArrayHelper::getValue($nbtype->params,'check_id_documents');
		$data->nb_disburse_frequency = JArrayHelper::getValue($nbtype->params,'disburse_frequency');
		$initial_signed = $data->initial_signed;
		if ($data->id && $data->state != NBS_INCOMPLETE && $data->state != NBS_COMPLETED) {
			$this->bindManagers($data);
		}

		if (!$agent = JentlacontentHelperOurTradie::getAgent($data->signup_manager)) {
			$this->setError('Unable to load your agency details');
			return false;
		}
		$agent_data = JentlacontentHelperOurTradie::loadAgentData($agent->id);
		$data->ll_individual_signing = !empty($agent_data) ? $agent_data->ll_individual_signing : 0;
		$data->ll_sign_title = 'Thanks for your sign-up';
		if ($data->state != NBS_COMPLETED && !empty($agent_data) && $agent_data->ll_sign_title)
			$data->ll_sign_title = $agent_data->ll_sign_title;
		$data->property_agent = $agent->id;
		$data->agent_trusted_source = $this->getPaymentName($agent->trusted_source);
		$data->agent_company_name = $agent->agent_company_name;
		if(!$data->property_owner == "" && !$data->property_id == ""){
			$bankModel = JModel::getInstance('BankAccounts', 'JentlaContentModel', array('ignore_request' => true));
			if (!$data->sharedbankaccount_cen = $bankModel->getBankSharedPercentage($data->property_owner,$data->property_id)) {
				$this->setError($bankModel->getError());
				return false;
			}
		}
		// Fields
		if (!$data->fields = $this->getFields($data->business_type)) {
			if (!$error = $this->getError())
				$this->setError('Unable to load fields');
			return false;
		}

		if (empty($data->id))
			return $data;

		$data->property			= json_decode($data->property);
		$data->rebates			= json_decode($data->rebates);
		$data->property_data	= json_decode($data->property_data);
		$data->property_pool	= json_decode($data->property_pool);
		$data->rental_standards	= json_decode($data->rental_standards);
		$data->attributes		= json_decode($data->attributes);
		$data->disclosure		= json_decode($data->disclosure);
		$data->viewings			= json_decode($data->viewings);
		$data->compliance		= json_decode($data->compliance);
		$data->mortgage_account	= json_decode($data->mortgage_account);
		$data->inspection		= json_decode($data->inspection);
		$data->marketing		= json_decode($data->marketing);
		$data->extra			= json_decode($data->extra);
		$company_info = $this->getPropertyData($prop_id);
		if(!empty($company_info)){
			$data->property_data->company_address1  = !empty($data->property_data->company_address1)  ? $data->property_data->company_address1 : $company_info->company_address1;
			$data->property_data->company_address2  = !empty($data->property_data->company_address2) ? $data->property_data->company_address2  : $company_info->company_address2;
			$data->property_data->company_abn       = !empty($data->property_data->company_abn) ? $data->property_data->company_abn : $company_info->company_abn;
			$data->property_data->company_acn       = !empty($data->property_data->company_acn) ? $data->property_data->company_acn : $company_info->company_acn;
			$data->property_data->company_pcode     = !empty($data->property_data->company_pcode) ? $data->property_data->company_pcode : $company_info->company_pcode;
			$data->property_data->company_suburb    = !empty($data->property_data->company_suburb) ? $data->property_data->company_suburb : $company_info->company_suburb;
			$data->property_data->company_state     = !empty($data->property_data->company_state) ? $data->property_data->company_state : $company_info->company_state;
			$data->property_data->company_reg_gst   = !empty($data->property_data->company_reg_gst) ? $data->property_data->company_reg_gst : $company_info->company_reg_gst;

		}
		if (!empty($data->property_data) && !empty($data->property_data->title_ref)){
			$data->property_data->title_ref = !is_array($data->property_data->title_ref) ? explode('<>', $data->property_data->title_ref) : $data->property_data->title_ref;
		}

		if (($data->management_end == '0000-00-00') || (Utilities::getDate($data->management_end) > JHtml::date('now', 'Y-m-d')))
		{
			if (!$property = $this->formattedProperty(array ('property_id' => $data->property_id))) {
				if (!$error = $this->getError())
					$this->setError('Unable to load property');
				return false;
			}

			if ((int)$data->past_id > -1) {
				if (property_exists($data->mortgage_account, 'property_id'))
					$data->mortgage_account->property_id = $data->property_id;
				if ($mortgage_account = $this->getMortgageAccount($data->property_id))
					$data->mortgage_account = $mortgage_account;

				if ($mortgage_account === false) {
					if (!$error = $this->getError())
						$this->setError('Unable to load mortgage_account');
					return false;
				}

				$property_state = strtoupper($property->property_state);
				if ($property_state == 'VIC' || $property_state == 'QLD') {
					jimport('jentla.rest');
					$rental_data = array();
					$rental_data['property_id'] = $data->property_id;
					$rental_data['property_state'] = $property_state;

					$rest = JRest::call('manager', 'com_jentlacontent.RentalStandards.getRentalStandards/site', (array)$rental_data);
					if ($rest_error = $rest->getError()) {
						$this->setError($rest_error);
						return false;
					}

					if (!$response = $rest->getResponse(true))
						$data->rental_standards = array();
					else
						$data->rental_standards = $response;
				}

				if ($property_state == 'VIC') {
					jimport('jentla.rest');
					$disclosure_data = array();
					$disclosure_data['property_id'] = $data->property_id;
					$disclosure_data['property_state'] = $property_state;
					$rest = JRest::call('manager', 'com_jentlacontent.Disclosures.getDisclosures/site', (array)$disclosure_data);

					if ($rest_error = $rest->getError()) {
						$this->setError($rest_error);
						return false;
					}

					if (!$response = $rest->getResponse(true))
						$data->disclosure = array();
					else
						$data->disclosure = $response;
				}

				$data->property_owner = $property->property_owner;
				$data->management_review = $property->management_review;
				$data->let_only = $property->let_only;
				// Populate ownership from property
				if (empty($data->ownership))
					$data->ownership = $property->ownership;
				if (empty($data->rent))
					$data->rent = $property->rent;
				if ($property->management_start != '0000-00-00'){
					if($data->past_id != 3){
						$data->management_start = $property->management_start;
					}
				}
			} else {
				$property->property_owner = $data->property_owner;
				$property->ppty_furnished = $data->property->ppty_furnished;
				$property->ppty_caraccomm = $data->property->ppty_caraccomm;
				$property->ppty_pets = $data->property->ppty_pets;
				$property->ppty_bedrooms = $data->property->ppty_bedrooms;
				$property->ppty_bathrooms = $data->property->ppty_bathrooms;
				$property->smokecode = $data->property->smokecode;
				$property->gatecode = $data->property->gatecode;
				$property->ppty_storage = $data->property->ppty_storage;
				$property->default_payment_system = $data->payment_system;
				$property->disbursement_type = $data->property->disbursement_type;
				$property->disburse_date = $data->property->disburse_date;
				$property->disburse_day = $data->property->disburse_day;
				$property->disburse_frequency = $data->property->disburse_frequency;
				$property->property_type = $data->property->property_type;
				$property->ownership = $data->ownership;
				$property->property_lot = $data->property->property_lot;
				$property->plannum = $data->property->plannum;
				$property->ctp_water = $data->property->ctp_water;
				$property->planname = $data->property->planname;
				$property->planaddr1 = $data->property->planaddr1;
				$property->planaddr2 = $data->property->planaddr2;
				$property->planaddr3 = $data->property->planaddr3;
				$property->property_manager = $data->property->property_manager;
				$property->bdm_ll_manager = $data->property->bdm_ll_manager;
				if (!empty($data->property->sales_agent))
					$property->sales_agent = !is_array($data->property->sales_agent) ? explode(',', $data->property->sales_agent) : $data->property->sales_agent;
			}
			$data->property = $property;
		}

		if(!empty($company_info)) {

			$company_info->building_manager = json_decode($company_info->building_manager);

			if(!empty($company_info->building_manager->name)) {
					$data->property_data->building_manager->name = !empty($data->property_data->building_manager->name) ? $data->property_data->building_manager->name : $company_info->building_manager->name;
			}
			if(!empty($company_info->building_manager->phone)) {
				$data->property_data->building_manager->phone = !empty($data->property_data->building_manager->phone) ? $data->property_data->building_manager->phone : $company_info->building_manager->phone;
			}
			if(!empty($company_info->building_manager->email)) {
				$data->property_data->building_manager->email = !empty($data->property_data->building_manager->email) ? $data->property_data->building_manager->email : $company_info->building_manager->email;
			}
		}

		if(!empty($data->property->plannum)) {
			$query = 'SELECT ju.id, ju.name, ju.lastname, ju.email, ju.cc_email, ju.phone, ju.contact_type, ju.strata_user'
				. ' FROM #__jentlausers as ju'
				. ' INNER JOIN #__property p ON p.property_manager = ju.id'
				. ' WHERE p.plannum = "' . $data->property->plannum . '" AND p.strata_property_type = "common" AND p.id > 0 AND p.property_agent = "' . $data->property_agent . '" LIMIT 1';
			$strata_manager_details = Utilities::getSqlResult($query, false);

			if($strata_manager_details) {
				$data->property_data->strata_manager->name = !empty($data->property_data->strata_manager->name) ? $data->property_data->strata_manager->name : $strata_manager_details['name'];

				$data->property_data->strata_manager->phone = !empty($data->property_data->strata_manager->phone) ? $data->property_data->strata_manager->phone : $strata_manager_details['phone'];

				$data->property_data->strata_manager->email = !empty($data->property_data->strata_manager->email) ? $data->property_data->strata_manager->email : $strata_manager_details['email'];
			}
		}

		if (empty($data->property_owner)) {
			$this->setError('Unable to find property_owner from nbs');
			return false;
		}

		if (!$data->property->landlords = $this->getLandlords($data->property_owner, $property)) {
			if (!$error = $this->getError())
				$this->setError('Unable to load landlords');
			return false;
		}
		$data->property->landlords = $this->formatLandlords($data->property->landlords, $data);

		$data->insurance = $this->getInsurance($data->id);
		if ($data->insurance === false) {
			$this->setError('Unable to load insurance');
			return false;
		}

		// format insurance
		if (!empty($data->insurance))
			$data->attributes->insurance = $data->insurance;
		if (empty($data->attributes->insurance->content_id))
			$data->attributes->insurance->content_id = $data->id;
		if (empty($data->attributes->insurance->property_id))
			$data->attributes->insurance->property_id = $data->property_id;
		if (empty($data->attributes->insurance->agency_id))
			$data->attributes->insurance->agency_id = $data->property_agent;
		if (empty($data->attributes->insurance->title))
			$data->attributes->insurance->title = 'Cetificate of currency';
		if (empty($data->attributes->insurance->map_user_id))
			$data->attributes->insurance->map_user_id = $data->property_owner;
		if (empty($data->attributes->insurance->user_id))
			$data->attributes->insurance->user_id = $user->get('id');
		if (empty($data->attributes->insurance->content_type))
			$data->attributes->insurance->content_type = 'nbs';

		if (empty($data->attributes->last_increase))
			$data->attributes->last_increase = '0000-00-00';
		if ($property->last_increase > 0)
			$data->attributes->last_increase = $property->last_increase;

		if (!$this->getOwnershipDocs($data)) {
			if (!$error = $this->getError())
				$this->setError('Unable to load ownership docs');
			return false;
		}

		if (!$this->getWaterCorpDocs($data)) {
			if (!$error = $this->getError())
				$this->setError('Unable to load Water docs');
			return false;
		}

		if (!$this->getStrataDoc($data)) {
			if (!$error = $this->getError())
				$this->setError('Unable to load Strata document');
			return false;
		}

		$data->initial_required = (!Utilities::getDate($data->initial_signed)) ? true : false;

		if (!$data->agency = JentlacontentHelperOurTradie::getAgencyProfile($data->property_id)) {
			$this->setError('Unable to load company profile');
			return false;
		}

		if (!empty($nbtype->fees_single))
			$data->fees_single = $nbtype->fees_single;
		if (!empty($nbtype->fees_multi))
			$data->fees_multi = $nbtype->fees_multi;
		if (!empty($nbtype->fees_associate))
			$data->fees_associate = $nbtype->fees_associate;

		// load fees
		$data->fees = $this->getFees($data->id, $for_user_group);
		$data->isrebates = false;
		$data->hiderebates = false;
		foreach($data->rebates as $rebate) {
			if (!empty($rebate->name) && !empty($rebate->relation) && !empty($rebate->nature_value)) {
				$data->isrebates = true;
				break;
			}

		}
		
		if ($data->state == '0') {
			if(!$data->isrebates) {
				if (!empty($nbtype->rebates)) {
					$data->rebates = $nbtype->rebates;
					$data->isrebates = true;
				}
			}
		}

		if(!empty($data->nbtype_params['hide_rebate_blank'])) {
			if(!$data->isrebates) {
				$data->hiderebates = true;
			}
		}

		// if (!$data->conditionHtml = $this->getReplacedConditionsByItem($data)) {
		// 	// Skip error right now
		// }

		//overwite default value from type
		if($data->attributes) {
			if(!$data->attributes->property_term)
				$data->attributes->property_term = JArrayHelper::getValue($nbtype->params, 'property_term');

			if(!$data->attributes->property_notice)
				$data->attributes->property_notice = JArrayHelper::getValue($nbtype->params, 'property_notice');

			if(!is_numeric($data->attributes->emergency_repair_prior)) {
				$prior = JArrayHelper::getValue($nbtype->params, 'emergency_repair_prior');
				$data->attributes->emergency_repair_prior = is_numeric($prior) ? $prior : 4;
			}
		}

		$app	= JFactory::getApplication();
		$menu	= $app->getMenu();
		$data->preview_link = 'index.php?option=com_jentlacontent&view=nbsignup&layout=default_preview&tmpl=raw&id=' . $data->id . '&type_id=' . $data->business_type;
		if ($previewItem = $menu->getItems('link', $data->preview_link, true))
			$data->preview_link .= '&Itemid=' . $previewItem->id;

		$content = $this->getBody($nbtype->content2);
		if (!empty($content)) {
			$data->preview_additional_link = 'index.php?option=com_jentlacontent&view=nbsignup&layout=default_preview_additional&tmpl=raw&id=' . $data->id . '&type_id=' . $data->business_type;
			if ($preview_additionalItem = $menu->getItems('link', $data->preview_additional_link, true))
				$data->preview_additional_link .= '&Itemid=' . $preview_additionalItem->id;
		}

		$data->landlord_link = 'index.php?option=com_jentlacontent&view=nbsignup&layout=landlord';
		if ($landlordItem = $menu->getItems('link', $data->landlord_link, true))
			$data->landlord_link .= '&Itemid=' . $landlordItem->id;
		$data->landlord_link .= '&id=' . $data->id . '&type_id=' . $data->business_type;

		$userGroups = JUserHelper::getUserGroups($user->id);
		if (in_array(LANDLORD_GROUP, $userGroups) && $data->state == NBS_UNSIGNED){
			$data->canAnalytic = true;
		}

		if (JRequest::getVar('print_data')) {
			print_r($data);
			exit;
		}

		if (is_numeric($data->property->smokecode) && $data->property->smokecode == 0)
			$data->property->smokecode = '';

		unset($data->ll_email_seen);

		return $data;
	}

	public function formatLandlords(&$landlords, $data)
	{
		Utilities::addLogs(print_r($data,true), 'formatLandlords24',true);
		foreach ($landlords as &$landlord)
		{
			if (!$landlord_id = JArrayHelper::getValue($landlord, 'id')) {
				$this->setError('Unable to find landlord id reference');
				return false;
			}

			if (!$unique_id = JArrayHelper::getValue($landlord, 'unique_id'))
				$landlord['unique_id'] = $this->createLLUniqueId($landlord_id, $data->property_agent);

			if (!$ref = JArrayHelper::getValue($landlord, 'ref'))
				$landlord['ref'] = $this->createLLRefId($landlord['unique_id'], $data->property_agent);

			$landlord['id_docs'] = $this->getIDDocs($landlord_id, $data);
			if ($this->getError())
				return false;

			$landlord['jsignature'] = $this->getSignature($landlord_id, $data->id, $data->property_id);
			if ($this->getError())
				return false;
		}

		return $landlords;
	}

	protected function getOwnershipDocs(&$data)
	{
		if (!$data->property_owner) {
			$this->setError('Empty owners not allowed to load ownership documents');
			return false;
		}

		$docsModel = JModel::getInstance('OurtradieDocs', 'JentlaContentModel', array('ignore_request' => true));
		$docsModel->setState('filter.doc_type', 'OSD');
		$docsModel->setState('filter.property_id', $data->property_id);
		$docsModel->setState('filter.list_type', 'nbs');
		$docsModel->setState('filter.list_id', $data->id);
		$docsModel->setState('filter.map_user_id', $data->property_owner);

		$data->ownership_docs = $docsModel->getItems();
		if ($data->ownership_docs === false) {
			$this->setError($docsModel->getError());
			return false;
		}

		foreach ($data->ownership_docs as $doc)
		{
			$doc->type = 'P';
			$doc->category = 29;
			$doc->property_id = $data->property_id;
			$doc->agency_id = $data->property->property_agent;
			$doc->list_type = 'nbs';
			$doc->list_id = $data->id;
			$doc->map_user_id = $data->property_owner;
		}

		return true;
	}
	 
	protected function getWaterCorpDocs(&$data)
	{
		if (!$data->property_owner) {
			$this->setError('Empty owners not allowed to load water documents');
			return false;
		}

		$docsModel = JModel::getInstance('OurtradieDocs', 'JentlaContentModel', array('ignore_request' => true));
		$docsModel->setState('filter.doc_type', 'WCD');
		$docsModel->setState('filter.property_id', $data->property_id);
		$docsModel->setState('filter.list_type', 'nbs');
		$docsModel->setState('filter.list_id', $data->id);
		$docsModel->setState('filter.map_user_id', $data->property_owner);

		$temp_water_corp_doc = $docsModel->getItems();
		if (!$water_corp_doc = current($temp_water_corp_doc)) {
			$this->setError('Invalid response from save recurrence');
			return false;
		}
		$water_corp_doc->type = 'P';
		$water_corp_doc->category = 130;
		$water_corp_doc->property_id = $data->property_id;
		$water_corp_doc->agency_id = $data->property->property_agent;
		$water_corp_doc->list_type = 'nbs';
		$water_corp_doc->list_id = $data->id;
		$water_corp_doc->map_user_id = $data->property_owner;
		 
		$data->water_corp_doc = $water_corp_doc;
		if ($data->water_corp_doc === false) {
			$this->setError($docsModel->getError());
			return false;
		}
		return true;
	}

	protected function getStrataDoc(&$data)
	{
		if (!$data->property_owner) {
			$this->setError('Empty owners not allowed to load strata document');
			return false;
		}

		$docsModel = JModel::getInstance('OurtradieDocs', 'JentlaContentModel', array('ignore_request' => true));
		$docsModel->setState('filter.doc_type', 'SD');
		$docsModel->setState('filter.property_id', $data->property_id);
		$docsModel->setState('filter.list_type', 'nbs');
		$docsModel->setState('filter.list_id', $data->id);
		$docsModel->setState('filter.map_user_id', $data->property_owner);

		$strata_documents = $docsModel->getItems();
		if (!$strata_document = current($strata_documents)) {
			$this->setError('Invalid strata document response');
			return false;
		}

		$strata_document->type = 'P';
		$strata_document->category = 147;
		$strata_document->property_id = $data->property_id;
		$strata_document->agency_id = $data->property->property_agent;
		$strata_document->list_type = 'nbs';
		$strata_document->list_id = $data->id;
		$strata_document->map_user_id = $data->property_owner;
		 
		$data->strata_document = $strata_document;
		if ($data->strata_document === false) {
			$this->setError($docsModel->getError());
			return false;
		}

		return true;
	}

	public function getJSItem($data)
	{
		Utilities::addLogs(print_r($data,true),'getJSItem24',true);
		$item = $this->getDetailItem();
		if ($this->getError())
			return false;

		return $item;
	}

	protected function saveProperty(&$data, $agent_id, $skip_prop_create = 0, $skip_notification = 1)
	{
		$user = JFactory::getUser();
		if (!$agent_id) {
			$this->setError('Empty agent_id not allowed');
			return false;
		}

		if (empty($data)) {
			$this->setError('Empty data not allowed to save property');
			return false;
		}

		/*
		if (!$this->validateProperty(array ('property' => $data)))
			return false;
		*/

		if (!$pk = intval(JArrayHelper::getValue($data, 'id'))) {
			$req_fields = array ('property_address1', 'property_suburb', 'property_state', 'property_postcode', 'landlords');
			foreach ($req_fields as $req_field) {
				if (!$value = JArrayHelper::getValue($data, $req_field)) {
					$this->setError('Empty ' . $req_field . ' not allowed');
					return false;
				}
			}
			$data['prop_incomplete'] = 1;
			//$data['bdm_ll_manager'] = $user->get('id');
			$data['propcreated_by'] = $user->get('id');
			$data['origin_from'] = 'MAA_Property';
		}
		$data['ModDate'] = JFactory::getDate()->toSql();

		$property = $data;
		$fields = array (
			'property_address1' => 'StreetName',
			'property_suburb'	=> 'Suburb',
			'property_state'	=> 'State',
			'property_postcode' => 'PostCode'
		);
		foreach ($fields as $key => $field) {
			if ($value = JArrayHelper::getValue($data, $key))
				$property[$field] = $value;
		}

		$landlords = JArrayHelper::getValue($data, 'landlords');
		if (!$this->haveValidLandlords($landlords))
			return false;

		// Set landlords from referenced variable
		unset($property['landlords']);
		$property['Landlords'] = array (
			'Landlord' => $landlords
		);

		jimport('jentla.rest');
		$post = array ( 'property' => $property, 'agent_id' => $agent_id, 'skip_prop_create' => $skip_prop_create, 'skip_notification' => $skip_notification);
		$rest = JRest::call('manager', 'com_jentlacontent.ourtradie.saveProperty/site', $post);
		if ($rest_error = $rest->getError()) {
			$this->setError($rest_error);
			return false;
		}
		if (!$response = $rest->getResponse(true)) {
			$this->setError('Empty response from property save action');
			return false;
		}

		if (!$property_id = JArrayHelper::getValue($response, 'id')) {
			$this->setError('Invalid response on save property: ' . json_encode($response));
			return false;
		}

		// Set property id
		$data = array_merge($response, $data);
		if (empty($response['unique_id'])) {
			$data['unique_id'] = 'op-' . $property_id;
			if (empty($response['property_id']))
				$data['property_id'] = $data['unique_id'];
		}

		// Set LL ids
		if ($landlords) {
			if (!$this->bindLandlordsResponse($landlords, $response))
				return false;
			unset($data['Landlords']);
			$data['landlords'] = $landlords;
		}

		return true;
	}

	protected function bindLandlordsResponse(&$landlords, $response)
	{
		if (!$saved_lls = JArrayHelper::getValue($response, 'landlords')) {
			$this->setError('Invalid ll save response');
			return false;
		}

		foreach ($landlords as &$landlord)
		{
			if ($ll_pk = JArrayHelper::getValue($landlord, 'id'))
				continue;

			if (!$ll_email = JArrayHelper::getValue($landlord, 'email')) {
				$this->setError('Invalid LL email reference to match PK');
				return false;
			}

			foreach ($saved_lls as $saved_ll)
			{
				$saved_ll_pk	= JArrayHelper::getValue($saved_ll, 'id');
				$saved_ll_email = JArrayHelper::getValue($saved_ll, 'email');
				if (!$saved_ll_pk || !$saved_ll_email) {
					$this->setError('Invalid save LL id/email reference to match PK');
					return false;
				}

				if ($ll_email == $saved_ll_email) {
					$landlord['id'] = $saved_ll_pk;
					$landlord['user_id'] = $saved_ll_pk;
					if (!$unique_id = JArrayHelper::getValue($landlord, 'unique_id'))
						$landlord['unique_id'] = $this->createLLUniqueId($saved_ll_pk, $response['property_agent']);
					if (!$ref = JArrayHelper::getValue($landlord, 'ref'))
						$landlord['ref'] = $this->createLLRefId($landlord['unique_id'], $response['property_agent']);
					$landlord['inactive'] = 0;
					$landlord['block'] = 0;
				}
			}
		}

		return true;
	}

	protected function haveValidLandlords(&$landlords)
	{
		if (empty($landlords)) {
			$this->setError('Empty landlords not allowed to validate');
			return false;
		}

		foreach ($landlords as $key => &$landlord) {
			$req_fields = array ('email', 'name');
			foreach ($req_fields as $req_field) {
				if (!$value = JArrayHelper::getValue($landlord, $req_field)) {
					$this->setError('Landlord #' . ($key+1) . ' - Empty ' . $req_field . ' not allowed');
					return false;
				}
			}
			$fields = array (
				'salutation' => 'Salutation',
				'bank_accname'	=> 'accountname',
				'name'	=> 'FirstName',
				'address' => 'mailingaddressline1',
				'address2' => 'mailingaddressline2'
			);
			foreach ($fields as $key => $field) {
				if ($value = JArrayHelper::getValue($landlord, $key))
					$landlord[$field] = $value;
			}
			if ($signature = JArrayHelper::getValue($landlord, 'signature'))
				$landlord['signature'] = json_encode($signature);
		}

		return true;
	}

	protected function haveNewLandlord($landlords, $property_owners)
	{
		if (!empty($landlords)) {
			$property_owners = explode(',' ,$property_owners);
			if(count($landlords) != count($property_owners)) 
				return true;
			foreach ($landlords as $landlord) {
				if (!$pk = JArrayHelper::getValue($landlord, 'id'))
					return true;
				if(!in_array($pk, $property_owners))
					return true;
			}
		}
		return false;
	}

	protected function beforeSaveProperty(&$property, $data)
	{
		if (!is_array($property))
			return;

		if (!$property_id = JArrayHelper::getValue($property, 'id'))
			return;

		// Unset if unnecessary
		unset($property['landlords']);

		if (empty($data))
			return;

		/*
		if ($ownership = JArrayHelper::getValue($data, 'ownership')) {
			if (empty($property['ownership']))
				$property['ownership'] = $ownership;
		}
		*/

		return true;
	}

	public function initialNBS($propertyId)
	{
		if(empty($propertyId)){
			$this->setError('Empty propertyId');
			return false;
		}

		$db = $this->getDbo();
		$query = $db->getQuery(true);
		$query->select('id');
		$query->from('#__nbs');
		$query->where('state IN (0, 1)');
		$query->where('property_id = ' .$propertyId);
		$db->setQuery($query);
		$nbs_property = $db->loadAssoc();
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		return $nbs_property;
	}

	public function beforeSaveNBS(&$signup, $data)
	{
		if(empty($signup['id']))
		{
			$nbs_property =$this->initialNBS($signup['property_id']);
			if($nbs_property === false)
				return false;

			if(!empty($nbs_property)){
				$this->setError('Duplicate nbs not allowed');
				return false;
			}
		}
		//unset($signup['property']);
		if ($property = JArrayHelper::getValue($data, 'property'))
		{
			if ($landlords = JArrayHelper::getValue($property, 'landlords')) {
				$owner_ids = JArrayHelper::getColumn($landlords, 'id');
				$signup['property_owner'] = implode(",", $owner_ids);
			}
		}

		if ($signup_property = JArrayHelper::getValue($signup, 'property')) {
			if ($signup_landlords = JArrayHelper::getValue($signup_property, 'landlords')) {
				foreach ($signup_landlords as $key => $signup_landlord) {
					$unset_fields = array ('id_docs', 'jsignature', 'signature', 'signobject');
					foreach ($unset_fields as $unset_field)
						unset($signup_landlord[$unset_field]);
					$signup['property']['landlords'][$key] = $signup_landlord;
				}
			}
		}

		if ($fees = JArrayHelper::getValue($signup, 'fees'))
			$signup['fees'] = json_encode($signup['fees']);

		if ($rebates = JArrayHelper::getValue($signup, 'rebates')) {
			foreach ($rebates as $key => $rebate) {
				if ($key > 0) {
					if (empty($rebate['name']) && empty($rebate['relation']) && empty($rebate['nature_value']))
						unset($rebates[$key]);
				}
			}
			$signup['rebates'] = json_encode($rebates);
		}

		if ($inspection = JArrayHelper::getValue($signup, 'inspection'))
			$signup['inspection'] = json_encode($inspection);

		if ($marketing = JArrayHelper::getValue($signup, 'marketing')) {
			if($market_details = JArrayHelper::getValue($marketing, 'details')) {
				foreach($market_details as $key => $market_detail) {
					if(!empty($market_detail['text']))
						break;
					if($key == count($market_details)-1)
						unset($marketing['details']);
				}
			}
			$signup['marketing'] = json_encode($marketing);
		}

		if (empty($signup['property_owner'])) {
			$this->setError('Signup owner reference empty');
			return false;
		}

		return true;
	}

	public function getDocumentByTitle($docsResponses, $title)
	{
		if (empty($docsResponses)) {
			$this->setError('Empty response not allowed to get document');
			return false;
		}

		if (empty($title)) {
			$this->setError('Empty title not allowed to get document');
			return false;
		}

		foreach ($docsResponses as $docsResponse) {
			$docstable = JArrayHelper::getValue($docsResponse, 'table');
			if ($docstable == 'documents') {
				$docsitems = JArrayHelper::getValue($docsResponse, 'data');
				foreach ($docsitems as $docsitem) {
					$docstitle = JArrayHelper::getValue($docsitem, 'title');
					if ($docstitle == $title)
						return $docsitem;
				}
			}
		}

		return array();
	}

	public function beforeSaveUsers($landlords) {
		foreach ($landlords as $landlord)
		{
			$email = JArrayHelper::getValue($landlord, 'email');
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$this->setError('Invalid email not allowed to save <br> ' .$email);
				return false;
			}

			if ($exist = $this->checkUserByEmail($landlord)) {
				$this->setError('EMAIL_EXIST_'.$email);
				return false;
			}
		}
		return true;
	}

	public function saveForm($data)
	{
		Utilities::addLogs(print_r($data,true), 'saveForm24',true);
		if ($post_in_data = JArrayHelper::getValue($data, 'data'))
			$data = $post_in_data;

		if (!$pk = $this->save($data)) {
			if (!$this->getError())
				$this->setError('Unable to save NBSU');
			return false;
		}

		return $this->getDetailItem($pk);
	}

	public function getDefaultBusinessType(){
		$typesModel = JModel::getInstance('NbsTypes', 'JentlaContentModel', array('ignore_request' => true));
		$typesModel->setState('list.select', '*');
		$typesModel->setState('list.direction', 'DESC');
		$typesModel->setState('list.ordering', 'home');
		$typesModel->setState('filter.state', '1');
		$nbtypes = $typesModel->getItems();

		if ($typesModel->getError()) {
			$this->setError('Cant create Nbsu with empty business type');
			return false;
		}
		return $nbtypes[0];
	}

	public function setDefaultValues(&$data)
	{
		if (empty($data)) {
			$this->setError('Error on default value load');
			return false;
		}

		if (empty($data['business_type']))
		{
			$defaultbustype = $this->getDefaultBusinessType();
			if(empty($defaultbustype)){
				return false;
			}
			$data['business_type'] = $defaultbustype->id;
		}

		if (!$nbtype = $this->getBusinessType($data['business_type'])) {
			if (!$this->getError())
				$this->setError('Unable to load business type');
			return false;
		}

		if (!empty($nbtype->fees_single) && empty($data['fees'])) {
			$fees = array ();
			$profile_fees = $nbtype->fees_single;
			foreach ($profile_fees as $profile_fee)
			{
				if (empty($profile_fee['enable']))
					continue;
				if (isset($profile_fee['show_on_maa']))
				{
					if (empty($profile_fee['show_on_maa']))
						continue;
				}
				unset($profile_fee['enable']);
				$fees[] = $profile_fee;
			}
			$data['fees'] = $fees;
		}
		
		return true;
	}

	public function save($data)
	{
		Utilities::addLogs(print_r($data,true), 'save24',true);
		$user = JFactory::getUser();
		if (empty($data)) {
			$this->setError('Empty data not allowed');
			return false;
		}
		$old_nbs = $data['oldNbsId'];
		Utilities::addLogs(print_r($old_nbs,true), 'old_nbs24',true);
		$item = null;
		if ($id = JArrayHelper::getValue($data, 'id'))
		{
			if (!$item = $this->getItem($id))
				return false;

			if ($item->modified != JArrayHelper::getValue($data, 'last_modified_time')) {
				$this->addLog(__METHOD__, 'Time mismatch for NBS: ' .$id. ' current_modified: ' . $item->modified . ' last_modified_time:: ' . JArrayHelper::getValue($data, 'last_modified_time') . ' Action by: ' .$user->get('id'), 'nbs_token_expired', false);
				$this->setError('Form token expired <a href="javascript:window.location.reload(true)"> Reload </a>');
				return false;
			}
		}

		if($data['property_data']){
			if ($title_ref = JArrayHelper::getValue($data['property_data'], 'title_ref')){
				$data['property_data']['title_ref'] =is_array($title_ref) ? implode('<>', $title_ref) : $title_ref;
			}
		}

		$agency_user_id = null;
		if (!JentlaContentHelperOurTradie::isPMGroup()) {
			if (empty($item)) {
				$this->setError('You\'re not authorised to create new signup');
				return false;
			}
			$agency_user_id = $item->property_agent;
		}

		if (!$agent = JentlacontentHelperOurTradie::getAgent($agency_user_id)) {
			$this->setError('Unable to load your agency details');
			return false;
		}
		$nbs_hide_doc = JArrayHelper::getValue($data['nbtype_params'], 'not_store_landlordid_doc');
		$data['agency_id'] = $agent->id;
		// if (!$this->validateProperty($data))
		// 	return false;

		$property = JArrayHelper::getValue($data, 'property');
		$property['past_id'] = JArrayHelper::getValue($data, 'past_id');
		if(!JArrayHelper::getValue($property, 'property_type'))
			$property['property_type'] = '';

		if ($sales_agent = JArrayHelper::getValue($property, 'sales_agent'))
			$property['sales_agent'] = is_array($sales_agent) ? implode(',', $sales_agent) : $sales_agent;
		else
			$property['sales_agent'] = '' ;

		$landlords = JArrayHelper::getValue($property, 'landlords');
		if (!$this->beforeSaveUsers($landlords))
			return false;

		$agent_data = JentlacontentHelperOurTradie::loadAgentData($agent->id);
		$ll_individual_signing = !empty($agent_data) ? $agent_data->ll_individual_signing : 0;
		if ($ll_individual_signing)
		{
			$landlord_emails = array();
			foreach ($landlords as $landlord)
			{
				$email = JArrayHelper::getValue($landlord, 'email');
				$cc_email = JArrayHelper::getValue($landlord, 'cc_email');
				if ($email)
					$landlord_emails[] = trim(strtolower($email));
				if ($cc_email)
					$landlord_emails[] = trim(strtolower($cc_email));
			}

			$unique_ll_emails = array_unique($landlord_emails);
			if (count($unique_ll_emails) < count($landlord_emails)) {
				$this->setError('Landlords are required to sign individually using unique email addresses. Please ensure each landlord has a distinct email or a valid separate mobile number.');
				return false;
			}
		}

		if (!$property_id = JArrayHelper::getValue($data, 'property_id'))
			$property_id = JArrayHelper::getValue($property, 'id');

		if ($property_id) {
			if (!$current_property_data = $this->getProperty(array ('property_id' => $property_id))) {
				if (!$this->getError())
					$this->setError('Unable to load property when check same owner: ' . $property_id);
				return false;
			}
		}

		$future_landlords = array();
		foreach ($landlords as $landlord) {
			if (JArrayHelper::getValue($landlord, 'id'))
				$future_landlords[] = JArrayHelper::getValue($landlord, 'id');
		}
		$current_landlords = array();
		if ($current_property_data->property_owner)
			$current_landlords = explode(',', $current_property_data->property_owner);
		//$diff_owner = array_merge(array_diff($future_landlords, $current_landlords), array_diff($current_landlords, $future_landlords));
		$same_owner = array_intersect($current_landlords, $future_landlords);

		if (JArrayHelper::getValue($data, 'past_id') == -1) {
			$log_name = 'nbs_future_changed';

			Utilities::addLogs('Old LL: ' . $current_property_data->property_owner, $log_name);
			Utilities::addLogs('New LL: ' . implode(',', $future_landlords), $log_name);
			Utilities::addLogs('Same LL: ' . print_r($same_owner, true), $log_name);

			//checking other NBS have a same owners.
			if ($cur_item = $this->haveActiveNBS($property_id, implode(',', $future_landlords))) {
				$this->setLogError(__METHOD__, 'NBSU already exsit for ' . $current_property_data->property_address1 . ' with same owner.<br> Please find the property from NBSU listing.', $log_name, false);
				return false;
			}
			Utilities::addLogs('past_id for the property: ' . $property_id . ' is: ' . $data['past_id'], $log_name);
			if (count($same_owner) > 0)
				$data['past_id'] = 1;
		}
		if (!$id && count($same_owner) > 0 && $property_id)
			$data['existing_ll'] = 1;
		Utilities::addLogs('past_id while exit the property: ' . $property_id . ' is: ' . $data['past_id'], 'nbs_future_changed');

		// set skip property
		$skip_prop_create = 0;
		if ($property_id > 0)
			$skip_prop_create = 1;

		$nbs_state = JArrayHelper::getValue($data, 'state');
		$skip_notification = 1;
		if (intval($nbs_state) > 0)
			$skip_notification = 0;

		if (!$pk = intval(JArrayHelper::getValue($data, 'id')))
		{
			if (!$property) {
				$this->setError('Empty property data not allowed');
				return false;
			}

			if (!$property_id = JArrayHelper::getValue($property, 'id'))
			{
				JModel::addIncludePath(JPATH_SITE . '/components/com_jentlacontent/models', 'JentlaContentModel');
				$propertyModel = JModel::getInstance('properties','JentlaContentModel');
				$current_property_data = array('property_agent'=>$property['property_agent'],'property_address1'=>$property['property_address1'],'property_postcode' =>$property['property_postcode']);
				$old_property = $propertyModel->isExistItem($current_property_data);
				if($old_property){
					$this->setError('Duplicate property not allowed');
					return false;
				}

				if (!$this->saveProperty($property, $agent->id, $skip_prop_create, $skip_notification))
					return false;
				if (!$property_id = JArrayHelper::getValue($property, 'id')) {
					$this->setError('Invalid response from save property');
					return false;
				}
			} else if ($landlords = JArrayHelper::getValue($property, 'landlords')) {
				if ($this->haveNewLandlord($landlords, $property['property_owner'])) {
					if (!$this->saveProperty($property, $agent->id, $skip_prop_create, $skip_notification))
						return false;
				}
			}
			$data['property_id'] = $property_id;

			if (!$this->setDefaultValues($data)) {
				return false;
			}
		} else if ($landlords = JArrayHelper::getValue($property, 'landlords')) {
			if ($this->haveNewLandlord($landlords, $property['property_owner'])) {
				if (!$this->saveProperty($property, $agent->id, $skip_prop_create, $skip_notification))
					return false;
			}
		}

		$nbs_id = JArrayHelper::getValue($data, 'id');
		$initial_signed =JArrayHelper::getValue($data, 'initial_signed');
		if ($nbs_id && !Utilities::getDate($initial_signed)) {
			$this->bindManagers($data);
		}

		// WARNING: Save order is import here.
		$tables = array();
		if ($landlords = JArrayHelper::getValue($property, 'landlords')) {
			$owner_ids = JArrayHelper::getColumn($landlords, 'id');
			$property['property_owner'] = implode(",", $owner_ids);
			$property['property_landlord'] = implode(",", $owner_ids);
			$tables[] = array ('table' => 'owner', 'key' => 'id', 'data' => $landlords);
			$tables[] = array ('table' => 'jentlausers', 'key' => 'id', 'data' => $landlords);
			$tables[] = array ('table' => 'users', 'key' => 'id', 'data' => $landlords);
			$uc_lls = $landlords;
			foreach($uc_lls as &$uc_ll) {
				unset($uc_ll['id']);
				$uc_ll['usertype'] = 'L';
				$uc_ll['agency_id'] = $agent->id;
			}
			$tables[] = array ('table' => 'user_contacts', 'unique_keys' => array('user_id'), 'data' => $uc_lls);
			$tables[] = array ('table' => 'user_uniqueid_map', 'unique_keys' => array('user_id'), 'data' => $uc_lls);
		}

		$documents = array ();
		if ($ownership_docs = JArrayHelper::getValue($data, 'ownership_docs'))
			$documents = array_merge($documents, $ownership_docs);

		if ($water_corp_doc = JArrayHelper::getValue($data, 'water_corp_doc'))
			array_push($documents, $water_corp_doc);

		if ($strata_doc = JArrayHelper::getValue($data, 'strata_document'))
			array_push($documents, $strata_doc);

		$first_owner_id = null;
		$jsignatures = array ();
		foreach ($landlords as $key => $landlord)
		{
			if (!$email = JArrayHelper::getValue($landlord, 'email')) {
				$this->setError('Empty email not allowed');
				return false;
			}

			if (!$first_owner_id)
				$first_owner_id = JArrayHelper::getValue($landlord, 'id');
			if ($id_docs = JArrayHelper::getValue($landlord, 'id_docs'))
				$documents = array_merge($documents, $id_docs);

			if ($jsignature = JArrayHelper::getValue($landlord, 'jsignature'))
			{
				if ($ll_individual_signing && $landlord['id'] != $user->id)
					continue;

				if (!isset($landlord['is_updated']) || !$landlord['is_updated']) {
					unset($landlords[$key]);
					continue;
				}

				if (!Utilities::getDate($jsignature['date']))
					$jsignature['date'] = JFactory::getDate()->format('Y-m-d');
				if(!isset($jsignature['deleted']))
					$jsignature['deleted'] = 0;
				$sign = JArrayHelper::getValue($jsignature, 'signature');
				$jsignature['signed_by'] = $user->id;
				$jsignature['device_detail'] = $_SERVER['REMOTE_ADDR'] . ' | ' . $_SERVER['HTTP_USER_AGENT'];
				if (is_array($sign)) {
					if($signed = JArrayHelper::getValue($sign, '0'))
						$jsignatures[] = $jsignature;
				} else {
					if ($sign)
						$jsignatures[] = $jsignature;
				}
			}
		}

		Utilities::addTextLogs($jsignatures,'jsignatures');
		if ($jsignatures)
			$tables[] = array ('table' => 'jentla_signature', 'key' => 'id', 'unique_keys' => array ('property_id', 'doc_type', 'nbs_id', 'user_id'), 'data' => $jsignatures);

		if ($documents) {
			foreach ($documents as &$document){
				$document['origin'] = 'N';
				$document['state'] = 1;
				if($document['category'] == 17 && $nbs_hide_doc){
					$document['state'] = -1;
				}
			}
			if (!$this->saveDocuments($documents)) {
				if (!$error = $this->getError())
					$error = 'NBS saved failed due to save documents';
				$this->addLog(__METHOD__, 'Save documents failed: ' . print_r($error, true) . ' Documents: ' .print_r($documents, true), 'nbs_upload_files', false);
				return false;
			}
		}

		$attributes = JArrayHelper::getValue($data, 'attributes');
		if ($insurance = JArrayHelper::getValue($attributes, 'insurance')) {
			if ($insurance['type'] != 'No insurance') {
				$insurance['owner_ids'] = JArrayHelper::getValue($data, 'property_owner');
				if (!$this->saveInsurance($insurance))
					return false;
			}
		}

		if ($bank_accounts = JArrayHelper::getValue($data, 'bank_accounts')) {
			if (!$this->saveBankAccounts($bank_accounts))
				return false;
		}

		$let_only = JArrayHelper::getValue($data, 'let_only');
		if (is_numeric($let_only))
			$property['let_only'] = $let_only;
		if ($management_start = JArrayHelper::getValue($data, 'management_start'))
			$property['management_start'] = $management_start;

		if ($management_review = JArrayHelper::getValue($data, 'management_review'))
			$property['management_review'] = $management_review;

		$data['property'] = $property;	// Reset property

		if ($payment_system = JArrayHelper::getValue($property, 'default_payment_system'))
			$data['payment_system'] = $payment_system;

		if (!$this->beforeSaveProperty($property, $data))
			return false;

		$past_id = (int)JArrayHelper::getValue($data, 'past_id');

		if (strtoupper(trim($payment_system)) == 'OP' && Utilities::getDate($agent->pp_live_date) && $agent->trusted_source == 'ourproperty')
		{
			if (!Utilities::getSqlResult('SELECT COUNT(*) FROM #__payment_property_map WHERE property_id =' . (int)$property_id . ' AND agency_id =' . (int)$agent->id, false))
			{
				if ($error = Utilities::getError()) {
					$this->setError($error);
					return false;
				}
				$livemode = $agent->pp_live_date <= JHtml::date('now','Y-m-d') ? 1 : 0;
				$payment_map = array (
					'property_id' => $property_id,
					'agency_id' => $agent->id,
					'live_date' => $livemode ? JHtml::date('now','Y-m-d') : $agent->pp_live_date,
					'livemode' => $livemode,
					'pending' => $livemode ? 0 : 1
				);
				$property['payment_system_pending'] = $livemode ? 0 : 1;

				$tables[] = array ('table' => 'payment_property_map', 'key' => 'id', 'unique_keys' => array ('property_id', 'agency_id'), 'data' => array ($payment_map));
			}

			if ($past_id > -1) {
				if ($mortgage_account = JArrayHelper::getValue($data, 'mortgage_account')) {
					if (empty($mortgage_account['user_id']))
						$mortgage_account['user_id'] = $first_owner_id;
					if (!$this->saveMortgageAccount($mortgage_account))
						return false;
				}

				if (!$this->afterSubmitNBS($property_id))
					return false;
			}
		}

		if ($past_id > -1)
		{
			if ($property_data = JArrayHelper::getValue($data, 'property_data')) {
				$property_data['property_id'] = $property_id;
				$tables[] = array ('table' => 'property_data', 'key' => 'id', 'unique_keys' => array ('property_id'), 'data' => array ($property_data));
			}

			$property_state = JArrayHelper::getValue($property, 'property_state');
			$property['ownership'] = $data['ownership'];
			$property['mortgage_amount']=$data['mortgage_account']['amount'];
			$property['mortgage_invoice_day']=$data['mortgage_account']['invoice_day'];
			$rental_standards = JArrayHelper::getValue($data, 'rental_standards');
			$step = JRequest::getCmd('step');
			if ($rental_standards && $step == "rental_standards" && ($property_state == 'VIC' || $property_state == 'QLD')) {
				jimport('jentla.rest');
				$layout = JRequest::getCmd('layout');
				$rental_standards['property_id'] = $property_id;
				$rental_standards['property_state'] = $property_state;
				$rental_standards['agency_id'] = $agent->id;
				$rental_standards['user_id'] = $user->get('id');
				$rental_standards['origin'] = 'N';
				if ($layout == 'landlord') {
					$rental_standards['llSiteLink'] = 1;
				}

				$rest = JRest::call('manager', 'com_jentlacontent.rentalstandards.saveRentalStandards/site', (array)$rental_standards);
				if ($rest_error = $rest->getError()) {
					$this->setLogError(__METHOD__, 'Invalid save rental_standards response.', 'nbs_save_error', false);
					$this->addLog(__METHOD__, 'nbs_rental_standards_save_error:' . $rest_error , 'nbs_save_error', false);
					return false;
				}
			}

			$disclosure = JArrayHelper::getValue($data, 'disclosure');
			if ($disclosure && $step == "disclosure" && $property_state == 'VIC') {
				jimport('jentla.rest');
				$layout = JRequest::getCmd('layout');
				$disclosure['property_id'] = $property_id;
				$disclosure['property_state'] = $property_state;
				$disclosure['agency_id'] = $agent->id;
				$disclosure['user_id'] = $user->get('id');
				$disclosure['origin'] = 'N';
				$disclosure['updated_by'] = $user->get('id');
				$disclosure['updated'] = JFactory::getDate()->toSql();
				if ($layout == 'landlord') {
					$disclosure['llSiteLink'] = 1;
				}

				$rest = JRest::call('manager', 'com_jentlacontent.disclosures.saveDisclosures/site', (array)$disclosure);
				if ($rest_error = $rest->getError()) {
					$this->setLogError(__METHOD__, 'Invalid save disclosure response.', 'nbs_save_error', false);
					$this->addLog(__METHOD__, 'nbs_disclosure_save_error:' . $rest_error , 'nbs_save_error', false);
					return false;
				}
			}

			if ($property_pool = JArrayHelper::getValue($data, 'property_pool')) {
				$property_pool['property_id'] = $property_id;
				$property_pool['agency_id'] = $agent->id;
				$tables[] = array ('table' => 'property_pools', 'key' => 'id', 'unique_keys' => array ('property_id'), 'data' => array ($property_pool));
			}
			if($data['past_id'] != 3 || !empty($data['is_submit'])){
				$tables[] = array ('table' => 'property', 'key' => 'id', 'data' => array ($property));
			}
			if (!$this->saveDisbursementRules($property))
				return false;
		}

		$signup = $data;
		if (!$this->beforeSaveNBS($signup, $data))
			return false;

		$tables[] = array ('table' => 'nbs', 'key' => 'id', 'data' => array($signup));
		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		if (!$result = $actionfieldModel->saveTables($tables, 1)) {
			$this->setError($actionfieldModel->getError());
			return false;
		}

		foreach ($result as $table) {
			$table_name = JArrayHelper::getValue($table, 'table');
			$table_data = JArrayHelper::getValue($table, 'data');
			if (!$table_name || !$table_data) {
				$this->setLogError(__METHOD__, 'Invalid save tables response.', 'nbs_save_error', false);
				$this->addLog(__METHOD__, 'Table list' .print_r($result, true), 'nbs_save_error', false);
				return false;
			}
			if ($table_name == 'nbs') {
				if (!$signup = end($table_data)) {
					$this->setLogError(__METHOD__, 'Invalid signup response from save tables response.', 'nbs_save_error', false);
					$this->addLog(__METHOD__, 'Table list' .print_r($result, true), 'nbs_save_error', false);
					return false;
				}
				if (!$pk = JArrayHelper::getValue($signup, 'id')) {
					$this->setLogError(__METHOD__, 'Unable to find signup id from save tables response', 'nbs_save_error', false);
					$this->addLog(__METHOD__, 'Table list' .print_r($result, true), 'nbs_save_error', false);
					return false;
				}
				$signup['id'] = $pk;
				$data['id'] = $pk;
			}
		}

		if ($fees = JArrayHelper::getValue($data, 'fees')) {
			if (!$this->saveFees($fees, $pk))
				return false;
		}

		if ($item->past_id > -1 && (int)$item->state > -1) {
			$exist_property = json_decode($item->property);
			$current_property = $data['property'];
			$new_owners = JArrayHelper::getValue($current_property, 'new_owners');
			$new_property_manager = $current_property['property_manager'] != $exist_property->property_manager ? 1 : 0;
			$new_bdm_ll_manager = $current_property['bdm_ll_manager'] != $exist_property->bdm_ll_manager ? 1 : 0;
			if ($new_property_manager || $new_bdm_ll_manager || $new_owners) {
				$update_roles['property_id'] = $item->property_id;
				$update_roles['property_agent'] = $item->property_agent;
				$this->addPropertyRoles($update_roles);
			}
		}

		if (!$this->afterSaveNBS($signup))
			return false;

		//if ($data['new_owners'])
			//return $data;
		Utilities::addLogs(print_r($pk,true),'pk25',true);
		Utilities::addLogs(print_r($data,true),'datapk25',true);
		// static $transferDone = false;
		if($data['past_id'] == 3 ){
			// if($data['check_id_document'] == 1 || $data['check'] ==2 ){
				$oldNbsId = $data['oldNbsId'];
				$newNbsId = $pk;
				$id_arr = array(
					'oldNbsId' => $oldNbsId,
					'newNbsId' => $newNbsId
				);
				if($oldNbsId && $newNbsId){
					Utilities::addLogs(print_r('hi',true),'hi25',true);
					$this->transferIdDocuments($id_arr);
					// $transferDone = true;
				}
			// }	
		}
		return $pk;
	}

	protected function validateItem($data, $is_initial = true)
	{
		if (!$pk = JArrayHelper::getValue($data, 'id')) {
			$this->setError('Empty primary key not allowed to validate');
			return false;
		}

		if (!$business_type = JArrayHelper::getValue($data, 'business_type')) {
			$this->setError('Empty business type not allowed to validate');
			return false;
		}

		$state = JArrayHelper::getValue($data, 'state');
		if (!$fields = $this->getFields($business_type, $state))
			return false;

		if (!$property = JArrayHelper::getValue($data, 'property')) {
			$this->setError('Empty property not allowed to validate');
			return false;
		}

		if (!$landlords = JArrayHelper::getValue($property, 'landlords')) {
			$this->setError('Empty landlords not allowed to validate');
			return false;
		}

		// $disclosures = JArrayHelper::getValue($data, 'disclosure');
		$viewings = JArrayHelper::getValue($data, 'viewings');
		$property_data = JArrayHelper::getValue($data, 'property_data');
		$property_pool = JArrayHelper::getValue($data, 'property_pool');
		$attributes = JArrayHelper::getValue($data, 'attributes');
		$compliance = JArrayHelper::getValue($data, 'compliance');
		$insurance = JArrayHelper::getValue($attributes, 'insurance');
		$property_type = JArrayHelper::getValue($property, 'property_type');
		$property_state = JArrayHelper::getValue($property, 'property_state');
		$marketing = JArrayHelper::getValue($data, 'marketing');
		$complianceDateFields = array( 'smoke_last_inspection', 'window_last_inspection', 'electrical_last_inspection', 'gas_last_inspection');
		$tenant_pay = array ('water', 'gas', 'telephone', 'cable', 'electricity', 'solar_rebate', 'solar', 'water_compliant');
		$strata = array ('plannum', 'planname', 'planaddr1', 'planaddr2', 'planaddr3', 'strata_contact', 'strata_phone', 'strata_email', 'property_lot');
		$isdropDownField = array ('ppty_furnished', 'ppty_pets', 'pool');
		$attributes_fields = array('acc_rep_maint', 'caretaking_cost', 'maint_cont_service', 'pay_maintenance_source', 'pay_tribunal_fees', 'propwaterflag', 'writ_execution', 'propcouncil', 'propsheriff', 'propins', 'law_mgmt_stmt', 'propcorp', 'water_consumption', 'pest_control' ,'gas_electric' ,'annual_service');
		$validateStrata = (in_array($property_type, array ('a', 'f')) && JArrayHelper::getValue($viewings['strata_schemes'], 'value') == 1) ? true : false;
		// $rental_standards = JArrayHelper::getValue($data, 'rental_standards');
		$extra = JArrayHelper::getValue($data, 'extra');

		$emptyFields = array ();
		foreach ($fields as $field)
		{
			$ownership_type = JArrayHelper::getValue($property_data, 'ownership_type');
			if ($ownership_type == 'c') {
				$companyFields = array ('company_address1', 'company_suburb', 'company_state', 'company_pcode', 'company_abn', 'company_acn', 'company_reg_gst');
				if (in_array($field->title, $companyFields))
					$field->initial_required = $field->final_required = 1;
			}

			$required = $is_initial ? 'initial_required' : 'final_required';
			if (!$field->$required)
				continue;

			$property_type = JArrayHelper::getValue($property, 'property_type');
			if (in_array($property_type, array ('o', 'u', 'p'))) {
				$excludedCategories = array('compliance', 'extra', 'tenant payment', 'attributes', 'disclosure', 'inspection');
				if (in_array(strtolower($field->category_name), $excludedCategories))
					continue;
			}

			$fieldname = end(explode('.', $field->title));
			if (strpos($field->title, 'landlords.') !== false) {
				$isEmpty = true;
				for ($i=0; $i<count($landlords);$i++) {
					$landlord = $landlords[$i];
					if ($fieldname == 'gst_registered') {
						$value = $landlord[$fieldname];
						if ($value == 0 || $value == 1) {
							$isEmpty = false;
							break;
						}
					}
					if ($landlord[$fieldname]) {
						$isEmpty = false;
						break;
					}
				}
				if (!$isEmpty)
					continue;
			} else if (strpos($field->title, 'viewings.') !== false) {
				$normal_fields = array ('with_risk', 'without_risk');
				if (in_array($fieldname, $normal_fields)) {
					if ($viewings[$fieldname] != "" || $viewings[$fieldname] != null)
						continue;
				}
				$viewing = JArrayHelper::getValue($viewings, $fieldname);
				if ($viewing != null) {
					$value = JArrayHelper::getValue($viewing, 'value');
					if ($value != "" || $value != null)
						continue;
				}
			} else if (strpos($field->category_name, 'Strata') !== false) {
				if ($validateStrata) {
					$strata_manager = JArrayHelper::getValue($property_data, 'strata_manager');
					$building_manager = JArrayHelper::getValue($property_data, 'building_manager');
					if (in_array($field->title, $strata)) {
						if (JArrayHelper::getValue($property, $field->title) != null)
							continue;
					}
					if ($field->title == 'title_ref') {
						Utilities::addLogs('Enter title ref', 'validate_item_nbs');
						if (JArrayHelper::getValue($property_data, $field->title) != null) {
							Utilities::addLogs(JArrayHelper::getValue($property_data, $field->title), 'validate_item_nbs');
							continue;
						}
					}
					if (strpos($field->title, 'building_manager.') !== false) {
						if ($field->title == 'building_manager.name') {
							if (JArrayHelper::getValue($building_manager, 'name') != null)
								continue;
						}
						if ($field->title == 'building_manager.phone') {
							if (JArrayHelper::getValue($building_manager, 'phone') != null)
								continue;
						}
						if ($field->title == 'building_manager.email') {
							if (JArrayHelper::getValue($building_manager, 'email') != null)
								continue;
						}
					}
					if ($field->title == 'fullname') {
						if (JArrayHelper::getValue($strata_manager, 'name') != null)
							continue;
					}
					if ($field->title == 'phone') {
						if (JArrayHelper::getValue($strata_manager, $field->title) != null)
							continue;
					}
					if ($field->title == 'email') {
						if (JArrayHelper::getValue($strata_manager, $field->title) != null)
							continue;
					}
					if ($field->title == 'strata_document') {
						$strata_document = JArrayHelper::getValue($data, 'strata_document');
						if (JArrayHelper::getValue($strata_document, 'path') != null)
							continue;
					}
				} else {
					continue;
				}
			} else if ($field->title == 'preferred_traders') {
				$preferred_traders_fields = array('elect_trd', 'pulmb_trd', 'build_trd', 'other_trd');
				$trader_found = false;
				foreach ($preferred_traders_fields as $preferred_traders_field) {
					$trader = JArrayHelper::getValue($property_data, $preferred_traders_field);
					if ($trader['name'] != '' || $trader['phone'] != '') {
						$trader_found = true;
						break;
					}
				}
				if ($trader_found)
					continue;
			} else if (in_array($field->title, $tenant_pay)) {
				if (JArrayHelper::getValue($property_data, $field->title) != null)
					continue;
			} else if (in_array($field->title, $complianceDateFields)) {
				$tmp = JArrayHelper::getValue($compliance, $field->title);
				if($tmp != "0000-00-00" && $tmp != "" && $tmp != null)
					continue;
			} else if (in_array($field->title, $attributes_fields)) {
				$value = JArrayHelper::getValue($attributes, $field->title);
				if (is_numeric($value))
					continue;
			} else if ($field->title == 'proof') {
				$isEmpty = true;
				if ($ownership_docs = JArrayHelper::getValue($data, 'ownership_docs')) {
					foreach ($ownership_docs as $ownership_doc) {
						if (!empty($ownership_doc['path'])) {
							$isEmpty = false;
							break;
						}
					}
				}
				if (!$isEmpty)
					continue;
			} else if ($field->title == 'water_corp_doc') {
				$water_corp_doc = JArrayHelper::getValue($data, 'water_corp_doc');
				if (JArrayHelper::getValue($water_corp_doc, 'path') != null)
					continue;
			} else if ($field->title == 'strata_document') {
				$strata_document = JArrayHelper::getValue($data, 'strata_document');
				if (JArrayHelper::getValue($strata_document, 'path') != null)
					continue;
			} else if ($field->title == 'service_agent') {
				$service_agent =  JArrayHelper::getValue($compliance, $fieldname);
				if (in_array($service_agent, array ('-1','0','1','2')))
					continue;
			} else if ($field->title == 'marketing_details') {
				$marketing_details = JArrayHelper::getValue($marketing, 'details');
				$isEmpty = true;
				foreach ($marketing_details as $marketing_detail) {
					if ($marketing_detail['text']){
						$isEmpty = false;
						break;
					}
				}
				if (!$isEmpty)
					continue;
			} else if ($field->title == 'lease_signage') {
				$lease_signage = JArrayHelper::getValue($marketing, 'lease_signage');
				if ($lease_signage == 0 || $lease_signage == 1)
					continue;
			} else if ($field->title == "id_documents") {
				$error_msg = '';
				if(JArrayHelper::getValue($data, 'nb_check_id_documents') == 1 || JArrayHelper::getValue($data, 'check_id_document') == 1) {
					$landlordIds = Utilities::arrayPivot($landlords, 'id');
					$landlordIds = array_keys($landlordIds);
					$error_msg = $this->validateIdDocuments($landlordIds,$pk);
				}
				if(JArrayHelper::getValue($data, 'nb_check_id_documents') != 1 && JArrayHelper::getValue($data, 'check_id_document') != 1) {
					for ($i=0; $i<count($landlords);$i++) {
						$landlord = $landlords[$i];
						if ($landlord['id_points'] < 100) {
							$error_msg .= $landlord['name']. ' needs to upload an ID Document with a maximum of 100 points <br>';
						}
					}
				}
				if (empty($error_msg))
					continue;
				else
					$field->label = $error_msg;
			} else if ($field->title == "management_start") {
				$past_id =  JArrayHelper::getValue($data, 'past_id');
				if ($past_id == 1)
					$field->label = 'Renewal Start Date';
				$let_only =  JArrayHelper::getValue($data, 'let_only');
				if ($let_only == 1 && $past_id != 1)
					continue;
				$management_start =  JArrayHelper::getValue($data, $fieldname);
				if ($management_start != '0000-00-00' && $management_start != '' && $management_start != null)
					continue;
			} else if ($field->title == "management_review") {
				$management_review =  JArrayHelper::getValue($data, $fieldname);
				if ($management_review != '0000-00-00' && $management_review != '' && $management_review != null)
					continue;
			} else if ($field->title == "available_date") {
				$available_date =  JArrayHelper::getValue($data, $fieldname);
				if ($available_date != '0000-00-00' && $available_date != '' && $available_date != null)
					continue;
			} else if ($field->title == 'bank_account') {
				$property_owner =  JArrayHelper::getValue($data, 'property_owner');
				$property_id =  JArrayHelper::getValue($data, 'property_id');

				$bankModel = JModel::getInstance('BankAccounts', 'JentlaContentModel', array('ignore_request' => true));
				if (!$bank_accounts = $bankModel->getBankAccounts($property_owner, $property_id)) {
					$this->setError($bankModel->getError());
					return false;
				}
				if ($bank_accounts)
				{
					foreach ($bank_accounts as $bank_account)
					{
						$isValid = true;
						$account_fields = array ('user_id', 'property_id', 'acc_name', 'acc_bsb', 'acc_no', 'shared_percentage');
						foreach ($account_fields as $account_field) {
							if (empty($bank_account[$account_field])) {
								$isValid = false;
								break;
							}
						}
						if ($isValid)
							break;
					}
				}
				if ($isValid)
					continue;
			}
			// else if ($field->title == 'disclosure'){
			// 	$isEmpty = false;
			// 	foreach ($disclosures as $value) {
			// 		if ($value == "" || $value == null || $value < 0){
			// 			$isEmpty = true;
			// 			break;
			// 		}
			// 	}
			// 	if (!$isEmpty)
			// 		continue;
			// }
			// else if ($field->title == 'rental_standards'){
			// 	$isEmpty = false;
			// 	foreach ($rental_standards as $value) {
			// 		if ($value == "" || $value == null || $value < 0){
			// 			$isEmpty = true;
			// 			break;
			// 		}
			// 	}
			// 	if (!$isEmpty)
			// 		continue;
			// }
			else if ($field->title == "type") {
				if ($type = JArrayHelper::getValue($insurance, 'type'))
					continue;
			} else if ($field->title == "disbursement") {
				$disbursement = JArrayHelper::getValue($property, 'disbursement_type');
				if (is_numeric($disbursement))
					continue;
			} else if($field->title == "disburse_date"){
				$disburse_date = JArrayHelper::getValue($property,'disburse_date');
				if(is_numeric($disburse_date))
					continue;
			}else if (in_array($field->title, $isdropDownField)) {
				$value = JArrayHelper::getValue($property, $field->title);
				if ($field->title == 'pool')
					$value = JArrayHelper::getValue($property_pool, 'trust_pool');
				if ($value != "" || $value != null)
					continue;
			} else if ($field->title == "last_increase") {
				$last_increase = JArrayHelper::getValue($attributes, 'last_increase');
				$isUnknownChecked = JArrayHelper::getValue($attributes, 'isUnknownChecked');
				if ($isUnknownChecked == 0 && $last_increase && $last_increase != '0000-00-00') {
						continue;
				}
				else if ($isUnknownChecked > 0) {
					$unknownReason = JArrayHelper::getValue($attributes, 'unknownReason');
					if ($unknownReason != '')
						continue;
				}
			} else if ($field->title == 'ctp_water') {
				$ctp_water = JArrayHelper::getValue($property, 'ctp_water');
				if ($ctp_water != '' || $ctp_water != null)
					continue;
			} else if ($field->title == 'caretaking') {
				$caretaking_fields = array('cleaner', 'gardner', 'pest_control', 'other');
				$caretaking = JArrayHelper::getValue($extra, 'caretaking', array());
				$isValid = false;
				foreach ($caretaking_fields as $caretaking_field) {
					$caretaking = JArrayHelper::getValue($caretaking, $caretaking_field);
					if ($caretaking != '' || $caretaking != null) {
						$isValid = true;
						break;
					}
				}
				if ($isValid)
					continue;
			} else if ($field->title == "maintenance_contracts") {
				$maintenance_fields = array('air_conditioning', 'lift', 'pool', 'other');
				$maintenance = JArrayHelper::getValue($extra, 'maintenance', array());
				$isValid = false;
				foreach ($maintenance_fields as $maintenance_field) {
					$maintenance_contract = JArrayHelper::getValue($maintenance, $maintenance_field);
					if ($maintenance_contract != '' || $maintenance_contract != null) {
						$isValid = true;
						break;
					}
				}
				if ($isValid)
					continue;
			} else if ($field->title == "principal_rep") {
				$principal_rep_fields = array('name', 'address', 'state', 'postcode', 'phone', 'home');
				$representative = JArrayHelper::getValue($extra, 'representative', array());
				$isValid = false;
				foreach ($principal_rep_fields as $principal_rep_field) {
					$principal_rep = JArrayHelper::getValue($representative, $principal_rep_field);
					if ($principal_rep != '' || $principal_rep != null) {
						$isValid = true;
						break;
					}
				}
				if ($isValid)
					continue;
			} else if ($field->title == "principal_solicitor") {
				$principal_solicitor_fields = array('name', 'address', 'state', 'postcode', 'phone', 'home');
				$solicitor = JArrayHelper::getValue($extra, 'solicitor', array());
				$isValid = false;
				foreach ($principal_solicitor_fields as $principal_solicitor_field) {
					$principal_solicitor = JArrayHelper::getValue($solicitor, $principal_solicitor_field);
					if ($principal_solicitor != '' || $principal_solicitor != null) {
						$isValid = true;
						break;
					}
				}
				if ($isValid)
					continue;
			} else {
				if ($data[$fieldname] != "" || $data[$fieldname] != null)
					continue;
				if ($property[$fieldname] != "" || $property[$fieldname] != null)
					continue;
				if ($property_data[$fieldname] != "" || $property_data[$fieldname] != null)
					continue;
				if ($attributes[$fieldname] != "" || $attributes[$fieldname] != null)
					continue;
				if ($compliance[$fieldname] != "" || $compliance[$fieldname] != null)
					continue;
			}
			$emptyFields[] = $field->label;
		}

		Utilities::addTextLogs($emptyFields, 'nbs_llsubmit');
		if (count($emptyFields) > 0) {
			$signup_status = $is_initial ? 'initial signup' : 'final signup';
			$this->setError("The following field(s) are required to complete the " . $signup_status .":<br><br>" . implode("<br>", $emptyFields));
			return false;
		}

		return true;
	}

	public function markAsComplete($data)
	{
		if ($post_in_data = JArrayHelper::getValue($data, 'data'))
			$data = $post_in_data;

		if (!$id = JArrayHelper::getValue($data, 'id')) {
			$this->setError('Empty id not allowed to mark as complete');
			return false;
		}

		$user = JFactory::getUser();
		$this->addLog(__METHOD__, ' Mark as Complete for: ' . $id . ' actioned by: ' .$user->id, 'nbs_llsubmit', false);

		$data = array (
			'id' => $id,
			'completed_origin' => 'Mark',
			'state' => NBS_COMPLETED
		);

		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		if (!$result = $actionfieldModel->saveItem($data, 'nbs')) {
			$this->setError($actionfieldModel->getError());
			return false;
		}
		$nbs_data = $this->getItem($id);
		$taskData = array();
		$taskData['context'] = 'NBSU';
		$taskData['context_id'] = $id;
		$taskData['user_ids'] = $nbs_data->property_owner;
		if (!Utilities::addJob($taskData, 'manager', 'com_jentlacontent.todotasks.completeTodoTask/site', 'NBS: Complete Todo ' . __METHOD__ . $id)) {
			if (!$error = Utilities::getError())
				$error = 'Unable to complete Todo task for NBS: ' . $id;
			$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_complete_todo', false);
			return false;
		}

		return $this->getDetailItem($id);
	}

	public function llsubmit($data)
	{
		if ($post_in_data = JArrayHelper::getValue($data, 'data'))
			$data = $post_in_data;

		if (empty($data)) {
			$this->setError('Empty data not allowed to send');
			return false;
		}
		$id = JArrayHelper::getValue($data, 'id');
		$user = JFactory::getUser();
		$this->addLog(__METHOD__, 'for: ' . $id . ' actioned by: ' .$user->id, 'nbs_llsubmit', false);
		$past_id = JArrayHelper::getValue($data, 'past_id');
		if (!$this->validateItem($data))
			return false;

		$this->bindManagers($data);
		$state = NBS_COMPLETED;
		$initial_signed = JArrayHelper::getValue($data, 'initial_signed');
		if (!$this->validateItem($data, false)) {
			$this->_errors = array();
			$state = NBS_INCOMPLETE;
		}

		if (!Utilities::getDate($initial_signed)) {
			$initial_signed = JFactory::getDate()->toSql();
			$payment_system = JArrayHelper::getValue($data, 'payment_system');
			if ($payment_system != 'OP')
				$data['console_updated'] = -1;
		}

		$final_signed = JArrayHelper::getValue($data, 'final_signed');
		if ($state == NBS_COMPLETED) {
			if (!Utilities::getDate($final_signed))
				$final_signed = JFactory::getDate()->toSql();

			$attributes = JArrayHelper::getValue($data, 'attributes');
			$last_increase = JArrayHelper::getValue($attributes, 'last_increase');
			if ($last_increase > 0)
				$data['property']['last_increase'] = $last_increase;
		}

		if(JArrayHelper::getValue($data,'check_id_document')){
			$user = JFactory::getUser();
			$this->addLog(__METHOD__, 'Again Review state : ' . $id . ' actioned by: ' .$user->id, 'nbs_llsubmit', false);
			if ($data['state'] == -3 || $data['state'] == -4) {
				if (!$this->sendReviewMail($data)) {             //review mail function call
					if (!$error = Utilities::getError())
						$error = 'Unable to send Review mail: ';
					$this->setError($error);
					return false;
				}
				if (!$this->sendResubmissionMail($data)) {
					if (!$error = Utilities::getError())
						$error = 'Unable to send Resubmission mail: ';
					$this->setError($error);
					return false;
				}
				$state = NBS_REVIEW;
			} else {
				if (!$this->sendReviewMail($data)) {                      //review mail function call
					if (!$error = Utilities::getError())
						$error = 'Unable to send Review mail: ';
					$this->setError($error);
					return false;
				}
				$state = NBS_REVIEW;
			}
		}
		$data['state'] = $state;
		$data['initial_signed'] = $initial_signed;
		$data['final_signed'] = $final_signed;
		$data['completed_origin'] = 'Landlord';
		$property_id = $data['property_id'];
		if($past_id == 3){
			$data['is_submit'] = true; // To indicate this is from submit
			$db = $this->_db;
			$query = $db->getQuery(true);
			$query->select('id,management_review');
			$query->from('#__nbs');
			$query ->where('property_id = ' . $db->quote($property_id) . ' AND past_id != 3');
			$db->setQuery($query);
			$old_ids = $db->loadObjectList();
			foreach($old_ids as &$old_id){
				$oldDate = $old_id->management_review; 
				$oldDateTimestamp = strtotime($oldDate);
				$currentDateTimestamp = strtotime(date('Y-m-d'));
				if ($oldDateTimestamp <= $currentDateTimestamp) {
					$this->markAsInactive($old_id);
					// $data['past_id'] = 4;
					if (!$this->sendRenewalCompletionMail($data)) {
						if (!$error = Utilities::getError())
							$error = 'Unable to send Resubmission mail: ';
						$this->setError($error);
						return false;
					}
				}
			}
		}
		if (!empty($data['past_id']))
			$data['property']['relet'] = 1;

		if (!$pk = $this->save($data))
			return false;

		if (!$this->afterComplete($pk)) {
			return false;
		}

		if (!$this->sendReport($data)) {
			if (!$this->getError())
				$this->setError('Error: Send NBS report');
			return false;
		}

		if ($state == NBS_INCOMPLETE) {
			$nbsmodel = JModel::getInstance('NbSignups', 'JentlaContentModel', array('ignore_request' => true));
			if (!$nbsmodel->scheduleIncompleteReminder($pk)) {
				$this->setLogError(__METHOD__, "Unable to add trigger. (nbs_id: $pk)", 'nbs_reminder', false);
				return false;
			}
		}
		//yoyo

			$taskData = array();
			$taskData['context'] = 'NBSU';
			$taskData['context_id'] = $id;
			$taskData['user_ids'] = $post_in_data['property_owner'];
			if (!Utilities::addJob($taskData, 'manager', 'com_jentlacontent.todotasks.completeTodoTask/site', 'NBS: Complete Todo ' . __METHOD__ . $id)) {
				if (!$error = Utilities::getError())
					$error = 'Unable to complete Todo task for NBS: ' . $id;
				$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_complete_todo', false);
				return false;
			}

		return $this->getDetailItem($pk);
	}

	public function sendReport($data)
	{
		$user = JFactory::getUser();
		if (!$user_id = $user->get('id')) {
			$this->setError('Unable to load user id NBS report');
			return false;
		}

		if (!$id = JArrayHelper::getValue($data, 'id')) {
			$this->setError('Unable to load signup id');
			return false;
		}

		if (!$item = $this->getItem($id))
			return false;

		if (!in_array($item->state, array (NBS_INCOMPLETE, NBS_COMPLETED,NBS_REVIEW)))
		{
			$this->setError('Invalid state to send report for nbs: ' . $id);
			return false;
		}

		if (empty($item->property_owner)) {
			$this->setError('Empty landlord(s) not allowed for nbs: ' . $id);
			return false;
		}

		if (empty($item->business_type)) {
			$this->setError('Unable to load bussiness type');
			return false;
		}

		if (!$nbsManagerId = $item->signup_manager) {
			$this->setError('Unable to load from user');
			return false;
		}

		if (!$nbtype = $this->getBusinessType($item->business_type))
			return false;


		$main_attachement = $this->prepareHTML(array(
			'id' => $id,
			'type_id' => $item->business_type,
			'tmpl' => 'raw'
		));

		$addition_attachement = $this->prepareHTML(array(
			'id' => $id,
			'type_id' => $item->business_type,
			'tmpl' => 'raw',
			'layout' => 'preview_additional'
		));

		if (empty($main_attachement)) {
			$this->setError('Unable to prepare report content for nbs: ' . $id);
			return false;
		}

		$id_data = array();
		$id_data['nbs_id'] = JArrayHelper::getValue($data, 'id');
		$id_data['property_owner'] = JArrayHelper::getValue($data, 'property_owner');
		$rest = JRest::call('manager', 'com_jentlacontent.nbsignup.getIDDocsZip/site', $id_data);
		if ($rest_error = $rest->getError()) {
			$this->setError($rest_error);
			return false;
		}

		if ($response = $rest->getResponse(true)) {
			$pmattachement['attachement'] = array($response);
		}

		$tenanted= array();
		if(!empty($data['property']['property_tenant'])){
			JModel::addIncludePath(JPATH_SITE . '/components/com_jentlacontent/models', 'JentlaContentModel');
			$tenantModel = JModel::getInstance('Tenancies', 'JentlaContentModel', array('ignore_request' => true));
			$tenanted =$tenantModel->getCurrentTenancy($data['property_id']);
		}

		if($tenanted){
			$ll_incomplete_email = "incomplete_email_for_landlord_existing_tenancy";
			$ll_completed_email ="completed_email_for_landlord_existing_tenancy"; 
		}
		else{
			$ll_incomplete_email = "ll_incomplete_email";
			$ll_completed_email ="ll_completed_email";
		}

		$ll_mail_tpl = $item->state == NBS_COMPLETED ? $ll_completed_email : $ll_incomplete_email;
		$pm_mail_tpl = $item->state == NBS_COMPLETED ? 'pm_completed_email' : 'pm_incomplete_email';

		$attachement['wkhtml_attachement'] = array (
			array (
				'content' => $main_attachement,
				'path'	  => 'images/attachments/signups'.DS.$id.DS.JHtml::date('now','Y-m-d'),
				'version' => array (
					'user_id' => $user_id,
					'list_id' => $id,
					'content_id' => $id,
					'content_type' => 'nbs',
					'content_subtype' => 'main',
					'status' => $item->state,
					'model' => 'nbsignup'
				)
			)
		);
		$pmattachement['wkhtml_attachement'] = array (
			array (
				'content' => $main_attachement,
				'path'	  => 'images/attachments/signups'.DS.$id.DS.JHtml::date('now','Y-m-d')
			)
		);

		$content = $this->getbody($addition_attachement);
		if ($content) {
			$attachement['wkhtml_attachement'][] = array (
				'content' => $addition_attachement,
				'path'	  => 'images/attachments/signups'.DS.$id.DS.JHtml::date('now','Y-m-d'),
				'version' => array (
					'user_id' => $user_id,
					'list_id' => $id,
					'content_id' => $id,
					'content_type' => 'nbs',
					'content_subtype' => 'additional',
					'status' => $item->state
				)
			);
			$pmattachement['wkhtml_attachement'][] = array (
				'content' => $addition_attachement,
				'path'	  => 'images/attachments/signups'.DS.$id.DS.JHtml::date('now','Y-m-d')
			);
		}

		$action = array(
			'force_send' => true
		);

		$action['inline_attach_site'] = 'landlord';
		$agencyModel = JModel::getInstance('AgencyModel', '', array('ignore_request' => true));
		if (jarrayhelper::getvalue($data,'nb_check_id_documents') == 1 && $dataAttachement = JArrayHelper::getValue($data, 'attachement')) {
				$pmattachement['attachement'] = JArrayHelper::getValue($pmattachement, 'attachement', array());
				$pmattachement['attachement'] = array_merge($pmattachement['attachement'], $dataAttachement);
				$property_owners = explode(',', $item->property_owner);
				foreach ($property_owners as $property_owner) {
					$attachement['attachement'] = $dataAttachement[$property_owner];
					if (!$sent = $agencyModel->sendMailWithContentType($id, 'nbsignup', $property_owner, $ll_mail_tpl, $action, $nbsManagerId, array(), $attachement, 'responsive_agency_cover')) {
						$this->setError($agencyModel->getError());
						return false;
					}
				}
		} else {
			if (!$sent = $agencyModel->sendMailWithContentType($id, 'nbsignup', $item->property_owner, $ll_mail_tpl, $action,  $nbsManagerId, array(), $attachement,'responsive_agency_cover')) {
				$this->setError($agencyModel->getError());
				return false;
			}
		}

		$cc_users = json_decode($item->property, true);
		$specific_cc_email = JArrayHelper::getValue($nbtype->params, 'specific_cc_email');
		$disburse_date = JArrayHelper::getValue($nbtype->params,'disburse_date');
		$disburse_day = JArrayHelper::getValue($nbtype->params,'disburse_day');
		$disburse_frequency = JArrayHelper::getValue($nbtype->params,'disburse_frequency');
		$nbsu_completed_cc_pm = JArrayHelper::getValue($nbtype->params, 'nbsu_completed_cc_pm');
		$nbsu_signed_cc_to = JArrayHelper::getValue($nbtype->params, 'nbsu_signed_cc_to','property_manager');
		$cc_signed_to =array('property_manager' => $item->property_manager,'bdm_ll_manager' => $cc_users['bdm_ll_manager'],'property_agent' => $cc_users['property_agent'],'specific_email' => $specific_cc_email);

		if(!empty($nbsu_completed_cc_pm) && $item->property_manager != $nbsManagerId && $pm_mail_tpl =='pm_completed_email'){
			$complete_cc_pm = $item->property_manager;
		}

		if($nbsu_signed_cc_to == 'specific_email' && !empty($specific_cc_email)){
			$bcc_email = array($cc_signed_to[$nbsu_signed_cc_to]);
		}
		else{
			if($cc_signed_to[$nbsu_signed_cc_to] != $nbsManagerId){
				 $bcc_id = $cc_signed_to[$nbsu_signed_cc_to];
			}
		}

		if(!empty($complete_cc_pm)){
			if(!empty($bcc_email)){
				$agencyModel = JModel::getInstance('AgencyModel', '', array('ignore_request' => true));
				$completed_pm = $agencyModel->loadUser($complete_cc_pm);
				array_push($bcc_email,$completed_pm['email']);
			}
			elseif(!empty($bcc_id) && $cc_signed_to[$nbsu_signed_cc_to] != $complete_cc_pm){
				$bcc_id .= ','. $complete_cc_pm;
			}
			else{
				$bcc_id = $complete_cc_pm;
			}
		}

		if (!empty($bcc_id)){
			$action=array('options'=>array(0=>array('bcc_id'=>$bcc_id)));
		}
		if(!empty($bcc_email)){
			$action=array('options'=>array(0=>array('bcc'=>$bcc_email)));
		}

		$action['inline_attach_site'] = 'propertymanager';
		if (!$sent = $agencyModel->sendMailWithContentType($id, 'nbsignup', $nbsManagerId, $pm_mail_tpl, $action, '', array(), $pmattachement, 'responsive_agency_cover')) {
			$this->setError($agencyModel->getError());
			return false;
		}

		return true;
	}

	function getBody($htmlcontent)
	{
		$dom = new DOMDocument;
		$mock = new DOMDocument;
		$dom->loadHTML($htmlcontent);
		$body = $dom->getElementsByTagName('body')->item(0);
		foreach ($body->childNodes as $child){
			$mock->appendChild($mock->importNode($child, true));
		}
		$content = $mock->saveHTML();
		$content = trim(preg_replace('/\s\s+/', ' ', $content));
		return $content;
	}

	public function prepareHTML($data)
	{
		$config = array('base_path' => JPATH_SITE . '/components/com_jentlacontent');
		if (!defined('JPATH_COMPONENT')) {
			define('JPATH_COMPONENT', JPATH_SITE.DS.'components'.DS.'com_jentlacontent');
		}
		require_once JPATH_COMPONENT.'/controller.php';
		$controller = JController::getInstance('JentlaContent', $config);
		$view = $controller->getView('Nbsignup','html');

		JModel::addIncludePath(JPATH_SITE . '/components/com_jentlacontent/models', 'JentlaContentModel');
		$model = JModel::getInstance('Nbsignup','JentlaContentModel');
		if (!$id = JArrayHelper::getValue($data,'id')){
			$this->setError('Unable to load signup id for prepareHTML');
			return false;
		}
		if (!$type_id = JArrayHelper::getValue($data,'type_id')){
			$this->setError('Unable to load type for prepareHTML');
			return false;
		}
		if (!$tmpl = JArrayHelper::getValue($data,'tmpl')){
			$this->setError('Unable to load component template for prepareHTML');
			return false;
		}
		$layout = JArrayHelper::getValue($data,'layout');

		$view->setModel($model, true);
		JRequest::setVar('id',$id);
		JRequest::setVar('type_id',$type_id);
		JRequest::setVar('tmpl',$tmpl);
		JRequest::setVar('isPdf', 1);

		if (empty($layout)){
			ob_start();
				$view->display('preview');
				$output = ob_get_contents();
			ob_end_clean();
			return $output;
		} else {
			ob_start();
				$view->display($layout);
				$output = ob_get_contents();
			ob_end_clean();
			return $output;
		}
		return true;
	}

	public function resend($data) {
		if ($post_in_data = JArrayHelper::getValue($data, 'data'))
			$item = $post_in_data;

		if (empty($item)) {
			$this->setError('Empty data not allowed to send');
			return false;
		}
		if(!$nbs_id = JArrayHelper::getValue($item,'id')) {
			$this->setError('Empty nbs id');
			return false;
		}
		$item = $this->getDetailItem($nbs_id);
		$data = json_decode(json_encode($item), true);

		if(!$result = $this->requestToLandlord($data)) {
			return false;
		}
		return $result;
	}

	public function submit($data)
	{
		Utilities::addLogs(print_r($data,true),'nbs_submit25',true);
		if ($post_in_data = JArrayHelper::getValue($data, 'data'))
			$data = $post_in_data;

		if (empty($data)) {
			$this->setError('Empty data not allowed to send');
			return false;
		}
		//yoyo
		$taskData = array();
		$taskData['context'] = 'NBSU';
		$taskData['context_id'] = JArrayHelper::getValue($data, 'id');
		$pastId = JArrayHelper::getValue($data, 'past_id'); 
		$taskData['title'] = ($pastId == -1) ? 'MAA Renewal Sign-up Request' : 'MAA Sign-up Request';
		$taskData['property_id'] = JArrayHelper::getValue($data, 'property_id');
		$taskData['due_date'] = JArrayHelper::getValue($data, 'management_start');
		$taskData['user_ids'] = JArrayHelper::getValue($data, 'property_owner');
		if (!Utilities::addJob($taskData, 'manager', 'com_jentlacontent.todotasks.createTodoTask/site', 'NBS: Create Todo ' . __METHOD__ . $data['id'])) {
			if (!$error = Utilities::getError())
				$error = 'Unable to create Todo task for NBS: ' . $data['id'];
			$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_create_todo', false);
			return false;
		}
		if ($data['state'] == NBS_INCOMPLETE || $data['state'] == NBS_COMPLETED)
			$data['state'] = NBS_INCOMPLETE;
		else
			$data['state'] = NBS_UNSIGNED;

		if (!$this->requestToLandlord((array)$data))
			return false;

		if($data['state'] == NBS_INCOMPLETE) {
			for($i = 0; $i < count($data['property']['landlords']); $i++) {
				if($data['property']['landlords'][$i]['jsignature'])
					$data['property']['landlords'][$i]['jsignature']['deleted'] = 1;
			}
		}

		$data['pm_requested'] = JFactory::getDate()->toSql();
		if (!$result = $this->saveForm($data))
			return false;

		if ($data['state'] == NBS_UNSIGNED && $data['past_id'] == 0) {
			if (!$this->updatePropertyFees($data['property_id']))
				return false;
		}

		return $result;
	}

	public function getFields($type_id, $state = NBS_UNSIGNED)
	{
		if (!$type_id) {
			$this->setError('Empty type_id not allowed to load fields');
			return false;
		}

		if (!$agent = JentlacontentHelperOurTradie::getAgent()) {
			$this->setError('Unable to load your agency details');
			return false;
		}
		if (!$agent_id = (int)$agent->get('id')) {
			$this->setError('Unable to find your agency reference');
			return false;
		}

		$fieldsModel = JModel::getInstance('NbsFields', 'JentlaContentModel', array('ignore_request' => true));
		$fieldsModel->setState('filter.id', $type_id);
		$layout = JRequest::getCmd('layout');
		if ($layout == 'landlord') {
			$fieldsModel->setState('filter.included', 1);
		} else {
			$fieldsModel->setState('filter.pm_included', 1);
		}

		if (!$fields = $fieldsModel->getItems()) {
			$this->setError($fieldsModel->getError());
			return false;
		}

		$pm_required_fields = array (
			'property_type', 'management_start', 'landlords.name', 'landlords.email', 'landlords.mobile', 'management_fee', 'ownership'
		);

		$result = array();
		foreach ($fields as $field)
		{
			$temp = $field;
			$temp->required = ($state == NBS_INCOMPLETE) ? $field->final_required : $field->initial_required;
			if ($layout != 'landlord') {
				$temp->editable = 1;
				$temp->required = 0;
				$temp->included = $field->pm_included;
				if (in_array($field->title, $pm_required_fields))
					$temp->required = 1;
			}

			if ($state == NBS_PAST) {
				$temp->editable = 0;
				$temp->required = 0;
			}

			if($agent_id == 1090003) {
				if(isset($temp->title) && $temp->title == 'ownership') {
					if($layout != 'landlord') {
					  	$temp->final_required = 0;
					  	$temp->required = 0;
					}
				}
			}

			if($agent_id == 401474){
				if($temp->title == 'sales_agent'){
					if($layout == 'landlord'){
						$temp->final_required = 0;
						$temp->required = 0;
					}
					else{
						$temp->required = 1;
					}
				}
			}

			if ($temp->required) {
				if ($temp->title != 'management_start')
					$temp->label .= '<span class="star">&nbsp;*</span>';
			}
			if ($layout == 'landlord') {
				if (!$temp->required && $temp->final_required)
					$temp->label .= '<span class="star">&nbsp;*</span>';
			}

			$temp->class = $temp->required ? 'required' : '';
			$result[$field->title] = $field;
		}

		return $result;
	}

	public function getConditions($business_type)
	{
		if (!$business_type) {
			$this->setError('Unable to load business type conditions');
			return false;
		}

		$conditionsModel = JModel::getInstance('NbsConditions', 'JentlaContentModel', array('ignore_request' => true));
		$conditionsModel->setState('filter.id', $business_type);
		$conditionsModel->setState('filter.included', 1);
		$conditionsModel->setState('list.ordering', 'repeatable_content ASC, ordering');
		$conditions = $conditionsModel->getItems();
		if ($error = $conditionsModel->getError()) {
			$this->setError($error);
			return false;
		}

		return $conditions;
	}

	public function getBusinessType($nbtype_id)
	{
		if (empty($nbtype_id)) {
			$this->setError('Empty sign-up type not allowed');
			return false;
		}

		if ($this->_nbtype === null)
			$this->_nbtype = array();

		if (isset($this->_nbtype[$nbtype_id]))
			return $this->_nbtype[$nbtype_id];

		$result = (object)array();
		$typeModel = JModel::getInstance('NbsType', 'JentlaContentModel', array('ignore_request' => true));
		$typeModel->setState($typeModel->getName() . '.id', $nbtype_id);
		if (!$result = $typeModel->getItem()) {
			$this->setError($typeModel->getError());
			return false;
		}

		if (!$agent = JentlacontentHelperOurTradie::getJentlaUser($result->agent_id)) {
			$this->setError('Unable to find your agent account details');
			return false;
		}
		$result->property_state = !empty($result->property_state) ? $result->property_state : $agent->state;

		// Set default fees
		if (!$default_fees = $typeModel->getDefaultFeeItems()) {
			if (!$error = $typeModel->getError())
				$error = 'Unable to load default fees';
			$this->setError($error);
			return false;
		}

		if (!empty($result->fees_single))
			$result->fees_single = $this->formatAdminFees($result->fees_single);
		else
			$result->fees_single = $default_fees;

		if (!empty($result->fees_multi))
			$result->fees_multi = $this->formatAdminFees($result->fees_multi);
		else
			$result->fees_multi = $default_fees;

		if (!empty($result->fees_associate))
			$result->fees_associate = $this->formatAdminFees($result->fees_associate);
		else
			$result->fees_associate = $default_fees;

		$result->conditions = $this->getConditions($nbtype_id);
		if ($this->getError())
			return false;

		$this->_nbtype[$nbtype_id] = $result;

		return $this->_nbtype[$nbtype_id];
	}

	public function formatAdminFees($fees)
	{
		$result = array ();
		foreach ($fees as $name => $fee)
		{
			if (isset($fee['show_on_maa'])) {
				if (!$fee['show_on_maa'])
					continue;
			} else {
				$fee['show_on_maa'] = 1;
			}
			$temp = $fee;
			$temp['name'] = $name;
			$result[$name] = $temp;
		}

		return $result;
	}
	public function findDuplicateNbs($property_id){
		$db = $this->getDbo();
		$query = $db->getQuery(true);
		$query->select('COUNT(id)');
		$query->from('#__nbs');
		$query->where('property_id = ' . (int)$property_id);
		$query->where('past_id = 3');
		$db->setQuery($query);
		$count = $db->loadResult();
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}
		if ($count >= 1) {
			$this->setError('Renewal NBS Already Exist');
			return false;
		}
		return true;	
	}
	public function validateProperty($data)
	{
		Utilities::addLogs(print_r($data, true), 'validateProperty', 'vp24',true);
		$user = JFactory::getUser();
		if ($post_in_data = JArrayHelper::getValue($data, 'data'))
			$data = $post_in_data;

		if (empty($data)) {
			$this->setError('Empty data not allowed');
			return false;
		}
		if($data['past_id'] == 3){

			return $this->findDuplicateNbs($data['property']['id']);
		}

		if (!$property = JArrayHelper::getValue($data, 'property')) {
			$this->setError('Empty property data not allowed');
			return false;
		}

		if (!$property_agent = JArrayHelper::getValue($property, 'property_agent')) {
			$this->setError('Empty property agent not allowed');
			return false;
		}

		if (!$property_address1	= JArrayHelper::getValue($property, 'property_address1')) {
			$this->setError('Empty address1 not allowed');
			return false;
		}

		if (!$property_postcode	= JArrayHelper::getValue($property, 'property_postcode')) {
			$this->setError('Empty postcode not allowed');
			return false;
		}

		$db	= $this->getDbo();
		$query = $db->getQuery(true);
		$query->select('COUNT(id)');
		$query->from('#__property');
		$query->where('property_address1 LIKE ' . $db->q(trim($property_address1)));
		$query->where('property_postcode = ' . $db->q(trim($property_postcode)));
		$query->where('property_agent = ' . (int)$property_agent);
		$query->where('strata_property_type != \'common\'');
		if ($property_id = JArrayHelper::getValue($property, 'id'))
			$query->where('id!=' . $db->q($property_id));

		// Setup the query
		$db->setQuery($query);
		$count = $db->loadResult();
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		if ($count > 0) {
			$this->setError('Property address already exist');
			return false;
		}

		if ($unique_id	= JArrayHelper::getValue($property, 'unique_id'))
		{
			$query->clear();
			$query->select('COUNT(id)');
			$query->from('#__property');
			$query->where('unique_id = ' . $db->q(trim($unique_id)));
			$query->where('property_agent = ' . (int)$property_agent);
			if ($property_id = JArrayHelper::getValue($property, 'id'))
				$query->where('id!=' . $db->q($property_id));

			$count = $db->loadResult();
			if ($db->getErrorNum()) {
				$this->setError($db->getErrorMsg());
				return false;
			}

			if ($count > 0) {
				$this->setError('Alpha code already exist');
				return false;
			}
		}

		$property_id = JArrayHelper::getValue($property, 'id');

		if(empty($property_id))
			$property_id = $this->hasInactiveProperty($property);

		if (!empty($property_id))
		{
			$filters = array ( 'property_id' => $property_id );
			if ($pk = JArrayHelper::getValue($data, 'id'))
				$filters['id'] = $pk;
			$hasItem = $this->hasItem($filters);
			if ($hasItem === false)
				return false;
			if ($hasItem) {
				if ($go_if_exist = JArrayHelper::getValue($data, 'go_if_exist')) {
					return array (
						'signup_id' => $hasItem->id,
						'signup_type' => $hasItem->business_type,
					);
				}
				$this->setError('This property already has an incomplete Sign-up.');
				return false;
			}
		}

		return true;
	}

	private function hasInactiveProperty($data)
	{
		$db	= $this->getDbo();
		$query = $db->getQuery(true);
		$query->select('GROUP_CONCAT(id)');
		$query->from('#__property');
		$query->where('property_address1 LIKE ' . $db->q(trim($data['property_address1'])));
		$query->where('property_postcode = ' . $db->q(trim($data['property_postcode'])));
		$query->where('property_agent = ' . (int)$data['property_agent']);
		$query->where('(inactive = 1 OR ( property_expiry IS NOT NULL AND property_expiry != \'0000-00-00 00:00:00\' AND property_expiry<CURDATE() ))');
		$query->where('strata_property_type != \'common\'');

		// Setup the query
		$db->setQuery($query);
		$property_ids = $db->loadResult();

		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		return $property_ids;
	}

	public function bindManagers(&$data)
	{
		if (is_array($data))
			$nbs_id = JArrayHelper::getValue($data, 'id');
		else
			$nbs_id = $data->id;
		$nbs = $this->getItem($nbs_id);
		$property = Utilities::getTable('property');
		$property->load($nbs->property_id);
		$nbsparams = Utilities::getSqlResult('SELECT `params` from #__nbs_user_types WHERE id = '.(int)$nbs->business_type, false);
		if (!empty($nbsparams)) {
			$typeparams = json_decode($nbsparams,true);
			$compliance_officer = JArrayHelper::getValue($typeparams,'compliance_officer', 'property_manager');
			$type_signed_manager = JArrayHelper::getValue($typeparams,'signed_manager', 'property_agent');
			$specific_signed_manager = JArrayHelper::getValue($typeparams,'specific_signed_manager', 0);
			$signup_manager = $property->$compliance_officer;
			$signup_role = $compliance_officer;
			$signed_manager = $property->$type_signed_manager;
			$signed_role = $type_signed_manager;
			if ($type_signed_manager == 'specific_user') {
				if ($specific_signed_manager > 0)
					$signed_manager = $specific_signed_manager;
				else
					$signed_manager = $property->property_agent;
			} else {
				$signed_manager = $property->$type_signed_manager;
			}
		}
		if (empty($signup_manager)) {
			if (!empty($property->property_manager)) {
				$signup_manager = $property->property_manager;
				$signup_role = 'property_manager';
			} else {
				$signup_manager = $property->property_agent;
				$signup_role = 'property_agent';
			}
		}
		if (empty($signed_manager)) {
			$signed_manager = $property->property_agent;
			$signed_role = 'property_agent';
		}
		if (is_array($data)) {
			$data['signup_manager'] = $signup_manager;
			$data['signup_role'] = $signup_role;
			$data['signed_manager'] = $signed_manager;
			$data['signed_role'] = $signed_role;
		} else {
			$data->signup_manager =  $signup_manager;
			$data->signup_role = $signup_role;
			$data->signed_manager = $signed_manager;
			$data->signed_role = $signed_role;
		}
		if ($print = JRequest::getVar('print')) {
			echo "NBS-Manager => " .$data['signup_manager'] . " NBS-Role => " .$data['signup_role'] . " For NBS id => " .$nbs_id . "<br>";
			echo "Signed-Manager => " .$data['signed_manager'] . " Signed-Role => " .$data['signed_role'] . " For NBS id => " .$nbs_id. "<br>";
			echo 'Query:: <br>';
			echo "UPDATE jos_nbs SET signed_manager='" .$data['signed_manager'] . "' ,signed_role='" .$data['signed_role'] . "', signup_manager='" .$data['signup_manager'] . "', signup_role='" .$data['signup_role'] . "' WHERE id=" .$nbs_id. ";<br>";
			exit;
		}
	}

	public function getNBSManager($nbs_id)
	{
		$nbs = $this->getItem($nbs_id);
		$property = Utilities::getTable('property');
		$property->load($nbs->property_id);
		$nbsparams = Utilities::getSqlResult('SELECT `params` from #__nbs_user_types WHERE id = '.(int)$nbs->business_type, false);
		$result = array ($property->property_manager, 'property_manager');

		if (!empty($nbsparams)) {
			$typeparams = json_decode($nbsparams,true);
			$compliance_officer = JArrayHelper::getValue($typeparams,'compliance_officer', 'property_manager');
			$result = array ($property->$compliance_officer, $compliance_officer);
		}
		return $result;
	}

	public function getNBSignedManager($nbs_id)
	{
		$nbs = $this->getItem($nbs_id);
		$property = Utilities::getTable('property');
		$property->load($nbs->property_id);
		$nbsparams = Utilities::getSqlResult('SELECT `params` from #__nbs_user_types WHERE id = '.(int)$nbs->business_type, false);
		$result = array ($property->property_agent, 'property_agent');

		if (!empty($nbsparams)) {
			$typeparams = json_decode($nbsparams,true);
			$signed_manager = JArrayHelper::getValue($typeparams,'signed_manager', 'property_agent');
			$result = array ($property->$signed_manager, $signed_manager);
		}

		return $result;
	}

	public function onTemplateBeforeRender($pk, $name, &$append)
	{
		if (empty($pk)) {
			$this->setError('Empty Nbs_id - Template Before Render');
			return false;
		}

		if (empty($name)) {
			$this->setError('Unable to load Templates On Template Before Render '.$name);
			return false;
		}

		if (!$nbs = $this->getDetailItem($pk)) {
			if (!$this->getError())
				$this->setError('Unable to load nbs details');
			return false;
		}

		if (!$nbs->property) {
			$this->setError('Unable to load property - Template before render');
			return false;
		}

		$nbsuMenu = array (
			'site_alias' => 'landlord',
			'menu_alias' => 'landlord-nb-sign-up'
		);
		$nbsuItemId = JentlacontentHelperOurTradie::getSiteMenuItemid($nbsuMenu);
		$nbs_ll_link = 'index.php?option=com_jentlacontent&view=nbsignup&layout=landlord&Itemid=' . $nbsuItemId . '&type_id=' . $nbs->business_type . '&id=' . $pk;

		$append['landlord_link'] = '(%%landlord%%)/##AUTO_LINK('.$nbs_ll_link.')##';
		$append['landlord_feedback'] = $this->feedbackHtml($nbs->property_id, 'nbsu', $nbs->id);

		$incomplete_ll_link = $nbs_ll_link . '&step=thanks';
		$append['incomplete_ll_link'] = '(%%landlord%%)/##AUTO_LINK('.$incomplete_ll_link.')##';

		$agencyModel = JModel::getInstance('AgencyModel', '', array('ignore_request' => true));
		$smokeTemplate = $agencyModel->loadTemplate('sa_compliance_form');
		$smokeId = $smokeTemplate->id;

		$smokeMenu = array (
			'site_alias' => 'landlord',
			'menu_alias' => 'smoke-alarm-compliance'
		);
		$smokeItemId = JentlacontentHelperOurTradie::getSiteMenuItemid($smokeMenu);
		$smokeLink = 'index.php?option=com_jentlacontent&view=form&form_id=' . $smokeId . '&Itemid=' . $smokeItemId . '&content_id=' . $nbs->id;
		$append['sa_compliance_link'] = '(%%landlord%%)/##AUTO_LINK('.$smokeLink.')##';

		$append['signature_date'] = !empty($append['signature_date']) ? $append['signature_date'] : '';
		$append['di_letter'] = is_null($append['di_letter']) ? '' : $append['di_letter'];
		$user = JFactory::getUser();
		if ($current_user = $agencyModel->loadUser($user->get('id')))
			$append['landlord_fullname'] = $current_user['fullname'];

		$property = $nbs->property;
		if (!$landlords = $property->landlords) {
			$this->setError('Unable to load owners - Template before render');
			return false;
		}
		$append['ownership_name'] = !empty($property->ownership) ? $property->ownership : '';
		$append['date_of_ownership_change'] = JHtml::date('now', 'd/m/Y');

		$landlordDetails = '';
		$landlords_name = array();
		foreach($landlords as $landlord) {
			$landlords_name[] = JArrayHelper::getValue($landlord, 'fullname');
			$landlordDetails .= '<p style="margin: 0 0 10px; font-family: Verdana,Geneva,sans-serif; font-size: 15px; text-align: left; color: #595959;">'.JArrayHelper::getValue($landlord, 'fullname').',</p>';
			$landlordDetails .= '<p style="margin: 0 0 10px; font-family: Verdana,Geneva,sans-serif; font-size: 15px; text-align: left; color: #595959;">'.JArrayHelper::getValue($landlord, 'phone_no').'</p>';
			$landlordDetails .= '<p style="margin: 0 0 10px; font-family: Verdana,Geneva,sans-serif; font-size: 15px; text-align: left; color: #595959;">'.JArrayHelper::getValue($landlord, 'mobile').'</p>';
			$landlordDetails .= '<p style="margin: 0 0 20px; font-family: Verdana,Geneva,sans-serif; font-size: 15px; text-align: left; color: #595959;">'.JArrayHelper::getValue($landlord, 'email').'.</p>';
		}

		$from_id = $nbs->signup_manager;
		$append['pm_requested_date'] = JFactory::getDate($nbs->pm_requested)->format('d/m/Y');

		if (!$signup_manager = $agencyModel->loadUser($from_id)) {
			$this->setError('Invalid or empty signup manager');
			return false;
		}

		$append['signup_manager_name'] = JArrayHelper::getValue($signup_manager,'fullname');
		$append['signup_manager_phone'] = JArrayHelper::getValue($signup_manager,'contact_number');
		$append['signup_manager_email'] = JArrayHelper::getValue($signup_manager,'email');

		$append['landlords_detail'] = $landlordDetails;
		$append['landlords_name'] = $append['new_landlords'] = implode(',' , $landlords_name);

		$append['old_landlords'] = '';
		if ($nbs->past_id > 0) {
			if (!$past_nbs = $this->getItem($nbs->past_id)) {
				if (!$this->getError())
					$this->setError('Unable to load past nbs details');
				return false;
			}

			if (!$past_landlords = $agencyModel->loadUser($past_nbs->property_owner)) {
				$this->setError('Unable to get past landlords details');
				return false;
			}

			$past_landlords_name = array();
			foreach($past_landlords as $past_landlord)
				$past_landlords_name[] = JArrayHelper::getValue($past_landlord, 'fullname');

			$append['old_landlords'] = implode(',', $past_landlords_name);
		}

		return true;
	}

	public function requestToLandlord($data)
	{
		Utilities::addLogs(print_r($data,true),'nbs_requestToLandlord25',true);
		if($nbs_id = JRequest::getInt('nbs_id')) {
			$data = (array) $this->getDetailItem($nbs_id);
		}

		if (empty($data)) {
			$this->setLogError(__METHOD__, 'Empty data not allowed to send mail.', 'nbs_email', false);
			return false;
		}

		if (!$pk = JArrayHelper::getValue($data, 'id')) {
			$this->setLogError(__METHOD__, 'Empty id not allowed to send.', 'nbs_email', false);
			return false;
		}

		$past_id = JArrayHelper::getValue($data,'past_id'); 
		$property_owner = JArrayHelper::getValue($data, 'property_owner');

		$from_user = JArrayHelper::getValue($data, 'signup_manager');
		if(empty($from_user)) {
			$this->setError('Empty NBS Manager id not allowed');
			return false;
		}

		$append = array();
		$site_url = Utilities::getSite('public_url');

		$property_owners = explode(",", $property_owner);
		foreach ($property_owners as $owner_id) {
			if (JentlacontentHelperOurTradie::checkValidUserById($owner_id, true))
				$to_ids[] = $owner_id;
		}

		$to_ids = (!empty($to_ids)) ? implode(",", $to_ids) : 0;

		if (empty($to_ids)) {
			$this->setLogError(__METHOD__, 'Invalid user(s) not allowed to send for id: ' .$pk, 'nbs_email', false);
			return false;
		}

		$tracking_url = $site_url.'/index.php?option=com_jentlacontent&task=Legacy.method&model=nbsignups&method=updateLLSeen&spacer=gap&nbs_id='.$pk;

		$agencyModel = JModel::getInstance('AgencyModel', '',array('ignore_request' => true));
		$ourDocsModel = JModel::getInstance('OurtradieDocs', 'JentlaContentModel', array('ignore_request' => true));

		$emailtpl_name = 'nbsignup_request_to_landlord_email';
		$sms_tpl_name = 'nbsignup_request_to_landlord_sms';
		$renewal_tpl_name = 'nbsignup_renewal_request_to_landlord_email';

		$business_type = JArrayHelper::getValue($data, 'business_type');
		$attachment_docs = $ourDocsModel->getDocumentsList(array('list_id' => $business_type, 'list_type' => 'nb_type'));

		if ($ourDocsModel->getError()) {
			$this->setError($ourDocsModel->getError());
			return false;
		}
		$docs_path = JArrayHelper::getColumn($attachment_docs,'path');
		$attachment = array('tracking_url'=>$tracking_url);
		$attachment['attachement'] = $docs_path;

		//CMA report data
		$property = JArrayHelper::getValue($data, 'property');
		$property_state = JArrayHelper::getValue($property, 'property_state');
		$property_id = JArrayHelper::getValue($data, 'property_id');
		$initial_signed = JArrayHelper::getValue($data, 'initial_signed');

		if($property_state == "WA" && $initial_signed == "0000-00-00 00:00:00"){
			$cmaModel = JModel::getInstance('CMAProperties', 'JentlaContentModel', array('ignore_request' => true));
			$cma_id = $cmaModel->findCMAIdByPropertyId($property_id);
			if($cma_id>0){
				if (!$pdflink = $cmaModel->getCMApath_fromapi($cma_id)){
					$this->addLog(__METHOD__, 'Empty CMA link Property Id ' . $property_id, 'nbs_email', false);
				}else{
					$attachment['attachement'][] = $pdflink;
				}
			}else{
				$this->addLog(__METHOD__, 'CMA id not found for Property Id ' . $property_id, 'nbs_email', false);
			}
		}
		 
		$action = array(
			'force_send' => true,
			'inline_attach_site' => 'landlord'
		);
		if($past_id == 3){
			$action['formaction'] = $renewal_tpl_name;
			if (!$agencyModel->sendMailWithContentType($pk,'nbsignup', $to_ids, $renewal_tpl_name, $action, $from_user, $append, $attachment, 'responsive_agency_cover')){
				$this->setLogError(__METHOD__, $agencyModel->getError(), 'nbs_email', false);
				return false;
			}
		}
		else{
			$action['formaction'] = $emailtpl_name;
			if (!$agencyModel->sendMailWithContentType($pk,'nbsignup', $to_ids, $emailtpl_name, $action, $from_user, $append, $attachment, 'responsive_agency_cover')){
				$this->setLogError(__METHOD__, $agencyModel->getError(), 'nbs_email', false);
				return false;
			}

			$action['formaction'] = $sms_tpl_name;
			if (!$agencyModel->sendSMSWithContentType($pk,'nbsignup', $to_ids, $sms_tpl_name, $action)) {
				$this->setLogError(__METHOD__, $agencyModel->getError(), 'nbs_email', false);
				return false;
			}
		}
		return true;
	}

	// Override file names

	protected function reformatFilesArray($name, $type, $tmp_name, $error, $size)
	{
		$extn = JFile::getExt($name);
		if (strtolower($extn) == 'png' || strtolower($extn) == 'gif')
			$extn = 'jpeg';
		$name = uniqid() . '.' . $extn;
		return array (
			'name'		=> $name,
			'compress'	=> 1,
			'type'		=> $type,
			'tmp_name'	=> $tmp_name,
			'error'		=> $error,
			'size'		=> $size,
			'filepath'	=> JPath::clean(implode(DIRECTORY_SEPARATOR, array('images', $this->folder, $name)))
		);
	}

	public function jqUploadFile()
	{
		$user = JFactory::getUser();
		if (!$user->get('id')) {
			$this->setError('Empty user not allowed');
			return false;
		}

		if (!$pk = JRequest::getInt('id')) {
			$this->setError('Empty pk not allowed');
			return false;
		}

		$date = JFactory::getDate();
		$folder = 'signups/' . $pk . '/' . $date->format('Y-m-d') . '/' . $user->get('id') . '/';
		$files = '';
		$files = JRequest::getVar('filedata', '', 'files', 'array');
		$this->addLog(__METHOD__, 'Files: ' . print_r($files, true) . 'Folder: ' .$folder, 'nbs_upload_files', false);
		if (!$result = $this->uploadFile('filedata', $folder)) {
			if (!$error = $this->getError())
				$error = 'Unable to upload this file for id :' .$pk;
			$this->setLogError(__METHOD__, $error, 'nbs_upload_files', false);
			return false;
		}

		if (!$path = JArrayHelper::getValue($result, 'filepath')) {
			$this->setError('Invalid upload response');
			return false;
		}

		if (!$size = JArrayHelper::getValue($result, 'size')) {
			$this->setError('Invalid upload response');
			return false;
		}
		$result = array (
			'size' => $size,
		 	'path' => $path
		);
		$this->addLog(__METHOD__, 'Success: ' . print_r($result, true), 'nbs_upload_files', false);
		return $result;
	}

	protected function getSignature($landlord_id, $signup_id, $property_id)
	{
		$result = array (
			'property_id' => $property_id,
			'nbs_id' => $signup_id,
			'user_id' => $landlord_id,
			'doc_type' => 'nbs',
			'date' => '',
			'signature' => '',
			'signed_by' => '',
			'signed_name' => '',
			'signed_username' => '',
			'device_detail' => ''
		);

		$signature_sql = 'SELECT id, nbs_id, property_id, `user_id`, `type`, `signature`, `date`, `doc_type`, `signed_by`, `device_detail`'
		. ' FROM #__jentla_signature WHERE deleted=0 and property_id=' . $property_id . ' AND nbs_id=' . $signup_id
		. ' AND `user_id`=' . $landlord_id . ' AND doc_type=' . $this->_db->q('nbs');
		$db	= $this->_db;
		$db->setQuery((string)$signature_sql);
		$signature = $db->loadAssoc();

		// Check for a database error.
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		if ($signature) {
			$signature['signature'] = json_decode($signature['signature']);
			$agencyModel = JModel::getInstance('AgencyModel', '', array('ignore_request' => true));
			$signed_by = JArrayHelper::getValue($signature, 'signed_by');
			if ($signed_user = $agencyModel->loadUser($signed_by)) {
				$signature['signed_name'] = JArrayHelper::getValue($signed_user, 'fullname');
				$signature['signed_username'] = JArrayHelper::getValue($signed_user, 'username');
			}
			$result = $signature;
		}

		return $result;
	}

	protected function getLastDocs($landlord_id)
	{
		if (!$landlord_id) {
			$this->setError('Empty landlord id not allowed to load last documents');
			return false;
		}

		$db	= $this->getDbo();
		$query = $db->getQuery(true);
		$query->select('docs.path, CONCAT(docs_map.doc_type_id, \'-\', docs_map.doc_subtype_id) AS unique_id, docs.state, docs.extra, docs.size');
		$query->from('#__documents AS docs');
		$query->join('INNER', '#__documents_map AS docs_map ON docs_map.document_id=docs.id');
		$query->where('docs.state = 1');
		$query->where('docs_map.user_id IN (' . $landlord_id . ')');
		$query->where('docs_map.doc_type_id > 0');
		$query->where('docs.log_time >= (CURDATE() - INTERVAL 28 DAY )');
		$query->group('unique_id');

		// Setup the query
		$db->setQuery($query);
		$documents = $db->loadAssocList('unique_id');
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		return $documents;
	}

	protected function getIDDocs($landlord_id, $data)
	{
		if (!$data->id)
			return array();

		$last_docs = $this->getLastDocs($landlord_id);
		if ($this->getError())
			return false;

		$docsModel = JModel::getInstance('OurtradieDocs', 'JentlaContentModel', array('ignore_request' => true));
		$docsModel->setState('filter.doc_type', '');
		$docsModel->setState('filter.map_user_id', $landlord_id);
		$docsModel->setState('filter.list_type', 'nbs');
		$docsModel->setState('filter.list_id', $data->id);
		if (!$id_docs = $docsModel->getItems()) {
			$this->setError($docsModel->getError());
			return false;
		}

		$load_last_doc = true;
		foreach ($id_docs as $doc)
		{
			if($doc->id) {
				$load_last_doc = false;
				break;
			}
		}

		foreach ($id_docs as $doc)
		{
			$doc->type = 'L';
			$doc->category = 17;
			$doc->property_id = $data->property_id;
			$doc->agency_id = $data->property_agent;
			$doc->extra = json_decode($doc->extra);
			if (empty($doc->extra))
				$doc->extra = (object) null;
			$doc->list_type = 'nbs';
			$doc->list_id = $data->id;
			$doc->map_user_id = $landlord_id;
			if (empty($doc->id) && $load_last_doc && $last_docs) {
				$unique_id = $doc->doc_type_id . '-' . $doc->doc_subtype_id;
				$last_doc = JArrayHelper::getValue($last_docs, $unique_id);
				if ($path = JArrayHelper::getValue($last_doc, 'path')) {
					$doc->path = $path;
					$doc->state = '1';
				}
				if ($extra = JArrayHelper::getValue($last_doc, 'extra'))
					$doc->extra = json_decode($extra);
				if ($size = JArrayHelper::getValue($last_doc, 'size'))
					$doc->size = $size;
			}
		}

		return $id_docs;
	}

	public function getStrataProperties($data)
	{
		$properties = array ();
		if (!$unique_id = JArrayHelper::getValue($data, 'unique_id')) {
			if (!$search = JRequest::getVar('term')) {
				$this->setError('Empty filter not allowed to find Strata Properties');
				return false;
			}
		}

		$db		= $this->_db;
		$query	= $db->getQuery(true);
		$query->select('p.id, IF(p.plannum IS NULL OR p.plannum = \'\', p.unique_id, p.plannum) AS plannum, p.property_lot');
		$query->select('j.fullname AS strata_contact, j.email AS strata_email, IF(j.phone IS NULL OR j.phone = \'\', j.mobile, j.phone) AS strata_phone');
		$query->select('d.building_manager, d.strata_manager');
		$query->select('CASE WHEN p.planname != "" THEN planname ELSE CONCAT_WS(", ", property_address1, property_address2, property_suburb) END as planname');
		$query->select('CASE WHEN p.planaddr1 != "" THEN planaddr1 ELSE property_address1 END as planaddr1');
		$query->select('CASE WHEN p.planaddr2 != "" THEN planaddr2 ELSE CONCAT_WS(", ", property_address2, property_suburb) END as planaddr2');
		$query->select('CASE WHEN p.planaddr3 != "" THEN planaddr3 ELSE CONCAT_WS(", ", property_state, property_postcode) END as planaddr3');
		$query->select('CONCAT_WS(" ", p.property_address1, CASE WHEN p.inactive THEN CONCAT_WS(" ",p.property_address2,"(Inactive)") ELSE p.property_address2 END, p.property_suburb, p.property_state, p.property_postcode) as address');
		$query->from('#__property AS p');
		$query->join('INNER', '#__jentlausers AS j ON j.id = p.property_manager');
		$query->join('LEFT', '#__property_data AS d ON d.property_id = p.id');
		$query->where('p.strata_property_type = \'common\'');

		if ($agent_id = JArrayHelper::getValue($data, 'agent_id')) {
			$query->where('p.property_agent IN (' . $agent_id . ')');
		} else {
			$agencies = JentlacontentHelperOurTradie::getAgencies();
			$query->where('p.property_agent IN (' . implode(',', $agencies) . ')');
		}

		// Set search term
		if ($search) {
			$search = $db->Quote('%' . $db->escape($search, true) .'%');
		} else {
			$search = $db->Quote($unique_id);
		}
		$query->where('(p.plannum LIKE ' . $search . ' OR p.unique_id LIKE' . $search . ')');

		$db->setQuery((string)$query, 0, 50);
		$properties = $db->loadAssocList();

		// Check for a database error.
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		if ($unique_id)
			return $properties;

		$return = array();
		foreach ($properties as $row) {
			$building_manager = json_decode($row['building_manager']);
			$row['common_property'] = $row['id'];
			$row['building_manager'] = empty($building_manager) ? (object) null : $building_manager;
			$strata_manager = json_decode($row['strata_manager']);
			if (empty($strata_manager)) {
				$row['strata_manager'] = array (
					'name'	=> $row['strata_contact'],
					'phone'	=> $row['strata_email'],
					'email'	=> $row['strata_phone'],
				);
			} else {
				$row['strata_manager'] = $strata_manager;
			}
			$option = array (
				'data' => $row,
				'value' => JArrayHelper::getValue($row, 'plannum', $row['id'])
			);
			$option['label'] = '<div class="container-item clearfix"><div class="text pull-left"><p class="head">' . $row['address'] . '</p></div></div>';
			$return[] = $option;
		}

		return $return;
	}

	public function getMortgageAccount($property_id)
	{
		if (empty($property_id)) {
			$this->setError('Empty property_id not allowed to load bank accounts');
			return false;
		}

		$db		= $this->_db;
		$query	= $db->getQuery(true);
		$query->select('jua.id, jua.user_id, jua.acc_name, jua.acc_no, jua.acc_bsb');
		$query->select('jua.acc_type, jua.property_id, jua.shared_percentage, jua.bank_accid');
		$query->select('(SELECT amount FROM #__disbursement_rules WHERE property_id = jua.property_id and type_id = 2 ORDER BY id DESC LIMIT 1) AS amount');
		$query->select('(SELECT invoice_day FROM #__disbursement_rules WHERE property_id = jua.property_id and type_id = 2 ORDER BY id DESC LIMIT 1) AS invoice_day');
		$query->from('#__jentlauser_accounts AS jua');
		$query->where('jua.id IN (SELECT bank_id FROM #__property_bankacc WHERE property_id = ' . (int)$property_id . ' AND disburse_type_id = 2)');
		$db->setQuery((string)$query);
		$account = $db->loadObject();

		// Check for a database error.
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		return $account;
	}

	public function saveBankAccounts($accounts)
	{
		if (empty($accounts)) {
			$this->setError('Emtpy data not allowed to save accounts');
			return false;
		}

		$user	= JFactory::getUser();
		$items	= array ();
		foreach ($accounts as $account)
		{
			if (!$acc_name = JArrayHelper::getValue($account, 'acc_name'))
				continue;
			$items[] = $account;
		}

		if (!$items)
			return true;

		jimport('jentla.rest');
		$post = array (
			'accounts'	=> $items,
			'rest_user' => $user->get('id'),
			'site_id'	=> Utilities::getSite('id')
		);

		$rest = JRest::call('manager', 'com_jentlacontent.nbsignup.saveBankAccounts/site', $post);
		if ($rest_error = $rest->getError()) {
			$this->setError($rest_error);
			return false;
		}

		if (!$response = $rest->getResponse(true)) {
			$this->setError('Empty response from property save action');
			return false;
		}

		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		foreach ($response as $table)
		{
			if (!$actionfieldModel->update($table)) {
				$this->setError($actionfieldModel->getError());
				return false;
			}
		}

		return $response;
	}

	public function saveMortgageAccount($account, $isCurrent = true)
	{
		if (empty($account)) {
			$this->setError('Emtpy data not allowed to save mortgage account');
			return false;
		}

		if ($isCurrent) {
			if (!$acc_no = JArrayHelper::getValue($account, 'acc_no'))
				return true;
		} else {
			$required_fields = array ('acc_name', 'acc_no', 'acc_bsb', 'user_id', 'property_id', 'amount');
			foreach ($required_fields as $required_field) {
				if (!$value = JArrayHelper::getValue($account, $required_field))
					return true;
			}
		}

		$user	= JFactory::getUser();
		jimport('jentla.rest');
		$post = array (
			'account'	=> $account,
			'rest_user' => $user->get('id'),
			'site_id'	=> Utilities::getSite('id')
		);

		$rest = JRest::call('manager', 'com_jentlacontent.nbsignup.saveMortgageAccount/site', $post);
		if ($rest_error = $rest->getError()) {
			$this->setError($rest_error);
			return false;
		}

		if (!$response = $rest->getResponse(true)) {
			$this->setError('Empty response from property save action');
			return false;
		}

		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		foreach ($response as $table)
		{
			if (!$actionfieldModel->update($table)) {
				$this->setError($actionfieldModel->getError());
				return false;
			}
		}

		return $response;
	}

	public function saveDisbursementRules($property)
	{
		if (empty($property)) {
			$this->setError('Emtpy data not allowed to save disbursement rules');
			return false;
		}

		if (JArrayHelper::getValue($property, 'default_payment_system') != 'OP')
			return true;

		if (!$disbursement_type = JArrayHelper::getValue($property, 'disbursement_type'))
			return true;

		if (!$property_id = JArrayHelper::getValue($property, 'id')) {
			$this->setError('Emtpy property id not allowed to save disbursement rules');
			return false;
		}
		$mortgage_invoice_day = JArrayHelper::getValue($property,'mortgage_invoice_day');
		$mortgage_amount = JArrayHelper::getValue($property,'mortgage_amount');
		$user	= JFactory::getUser();
		jimport('jentla.rest');
		$post = array (
			'amount' => $mortgage_amount,
			'invoice_day' => $mortgage_invoice_day,
			'property_id'	=> $property_id,
			'disbursement_type'	=> $disbursement_type,
			'rest_user' => $user->get('id'),
			'site_id'	=> Utilities::getSite('id')
		);

		$rest = JRest::call('manager', 'com_jentlacontent.nbsignup.saveDisbursementRules/site', $post);
		if ($rest_error = $rest->getError()) {
			$this->setError($rest_error);
			return false;
		}

		if (!$response = $rest->getResponse(true)) {
			$this->setError('Empty response from property save action');
			return false;
		}

		return $response;
	}

	public function saveInsurance($insurance)
	{
		if (empty($insurance)) {
			$this->setError('Empty insurance data not allowed to save');
			return false;
		}

		if ($path = JArrayHelper::getValue($insurance, 'path')) {
			if (!$policyno = JArrayHelper::getValue($insurance, 'policyno')) {
				$this->setError('Policy no. required to save insurance document');
				return false;
			}
		}

		if (!$policyno = JArrayHelper::getValue($insurance, 'policyno'))
			return true;

		$user	= JFactory::getUser();
		jimport('jentla.rest');
		$post = array (
			'insurance'	=> $insurance,
			'rest_user'	=> $user->get('id'),
			'site_id'	=> Utilities::getSite('id')
		);

		$rest = JRest::call('manager', 'com_jentlacontent.nbsignup.saveInsurance/site', $post);
		if ($rest_error = $rest->getError()) {
			$this->setError($rest_error);
			return false;
		}

		if (!$response = $rest->getResponse(true)) {
			$this->setError('Empty response from insurance save action');
			return false;
		}

		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		foreach ($response as $table)
		{
			if (!$actionfieldModel->update($table)) {
				$this->setError($actionfieldModel->getError());
				return false;
			}
		}

		return $response;
	}

	public function getInsurance($pk)
	{
		if (!$pk) {
			$this->setError('Empty content id to insurance save');
			return false;
		}

		$db		= $this->_db;
		$query	= $db->getQuery(true);
		$query->select('si.id, si.agency_id, si.property_id, si.company, si.type, si.policyno, si.expirydate, si.amount');
		$query->select('si.contents_sum_insured, si.document_id, si.paid_from_rent, jd.title, jd.path, jd.size,si.content_id,si.content_type,jd.user_id');
		$query->from('#__sync_insurance as si');
		$query->join('left','jos_documents as jd on jd.id = si.document_id');
		$query->where('si.content_id ='. $pk);
		$query->where('si.content_type =' . $db->q('nbs'));
		$db ->setQuery((string) $query);
		$insurance = $db->loadobject();

		// Check for a database error.
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		return $insurance;
	}

	public function getPropertyData($property_id)
	{
		if (!$property_id) {
			$this->setError('Empty property_id not allowed to load current nbs');
			return false;
		}

		$db		= $this->_db;
		$query	= $db->getQuery(true);
		$query->select('company_address1, company_address2, company_suburb, company_state, company_pcode, company_abn, company_acn');
		$query->select('company_reg_gst, ownership_type, building_manager, inv_parse_options, ref_source, ref_lead');
		$query->from('#__property_data');
		$query->where('property_id IN ('. (int)$property_id . ')');
		$db->setQuery((string)$query);
		$item = $db->loadObject();

		// Check for a database error.
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		return $item;
	}

	public function getPropertyPool($property_id)
	{
		if (!$property_id) {
			$this->setError('Empty property_id not allowed to load current nbs pool data');
			return false;
		}

		$db		= $this->_db;
		$query	= $db->getQuery(true);
		$query->select('p.trust_pool, p.bcc_shared, p.last_inspected, p.bcc_cert, p.pool_expiry');
		$query->from('#__property_pools as p');
		$query->where('property_id = '. (int)$property_id);
		$db->setQuery((string)$query);
		$item = $db->loadObject();

		// Check for a database error.
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		return $item;
	}

	public function getPastItem($future_id)
	{
		if (!$future_id) {
			$this->setError('Empty future_id not allowed to load past nbs');
			return false;
		}

		$db		= $this->_db;
		$query	= $db->getQuery(true);
		$query->select('nbs.*');
		$query->from('#__nbs AS nbs');
		$query->where('nbs.future_id IN ('. (int)$future_id . ')');
		$db->setQuery((string)$query);
		$item = $db->loadObject();

		// Check for a database error.
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		return $item;
	}

	public function getCurrentItem($property_id)
	{
		if (!$property_id) {
			$this->setError('Empty property_id not allowed to load current nbs');
			return false;
		}

		$db		= $this->_db;
		$query	= $db->getQuery(true);
		$query->select('nbs.*, nbs.id as signup_id');
		$query->from('#__nbs AS nbs');
		$query->where('nbs.property_id IN ('. (int)$property_id . ')');
		$query->where('nbs.state IN (' . implode(',', array (NBS_INCOMPLETE, NBS_COMPLETED, NBS_REVIEW, NBS_DISPUTED)) . ')');
		$query->where('nbs.past_id > -1');
		$query->where('(nbs.management_end = ' . $db->q('0000-00-00') . ' OR nbs.management_end > ' . $db->q(JHtml::date('now', 'Y-m-d')) . ')');
		$query->select('p.management_expiry');
		$query->join('INNER', '#__property AS p ON p.id=nbs.property_id');
		$db->setQuery((string)$query);
		$item = $db->loadObject();

		// Check for a database error.
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		return $item;
	}

	public function haveActiveNBS($property_id, $property_owners)
	{
		if (!$property_id) {
			$this->setError('Empty property_id not allowed to load current nbs');
			return false;
		}

		$db		= $this->_db;
		$query	= $db->getQuery(true);
		$query->select('nbs.*');
		$query->from('#__nbs AS nbs');
		$query->where('nbs.property_id IN ('. (int)$property_id .')');
		$query->where('nbs.property_owner IN ("'. $property_owners .'")');
		$query->where('nbs.past_id > -1');
		$query->where('(nbs.management_end = ' . $db->q('0000-00-00') . ' OR nbs.management_end > ' . $db->q(JHtml::date('now', 'Y-m-d')) . ')');
		$db->setQuery((string)$query);
		$item = $db->loadObject();

		// Check for a database error.
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		return $item;
	}

	public function canActivate($item)
	{
		// Skip if it's unsigned
		if (!in_array($item->state, array (NBS_INCOMPLETE, NBS_COMPLETED,NBS_REVIEW))) {
			$this->setError('NBSU[' . $item->id . ']: Unsigned not allowed to activate.');
			return false;
		}

		if ($item->past_id > -1) {
			$this->setError('NBSU[' . $item->id . ']: May be past or activated.');
			return false;
		}

		if ($item->management_end != '0000-00-00' && $item->management_end <= JHtml::date('now', 'Y-m-d')) {
			$this->setError('NBSU[' . $item->id . ']: Management expire in past.');
			return false;
		}

		if ($item->management_start > JHtml::date('now', 'Y-m-d')) {
			$this->setError('NBSU[' . $item->id . ']: Management start in future.');
			return false;
		}

		return true;
	}

	public function markAsPast($data)
	{
		if (!$future_id = JArrayHelper::getValue($data, 'future_id')) {
			$this->setError('Empty future_id not allowed to mark as past');
			return false;
		}

		$past_item = $this->getPastItem($future_id);
		if ($past_item === false) {
			if (!$this->getError())
				$this->setError('Unable to load past item of future_id: ' . $future_id);
			return false;
		}

		if ($past_item)
			return $past_item->id;

		if (!$future_item = $this->getItem($future_id))
			return false;

		if (!$property_id = $future_item->property_id) {
			$this->setError('Empty property_id not allowed to mark as past');
			return false;
		}

		if (!$future_owner_ids = explode(',', $future_item->property_owner)) {
			$this->setError('Empty property_owners not allowed to mark as past');
			return false;
		}

		if (!$property = $this->getProperty(array ('property_id' => $property_id))) {
			if (!$this->getError())
				$this->setError('Unable to load property when mark as past: ' . $property_id);
			return false;
		}

		$date = JFactory::getDate();
		$content_access = array();
		$user_property_maps = array();
		if ($past_owner_ids = explode(',', $property->property_owner))
		{
			foreach ($past_owner_ids as $past_owner_id) {
				$tmp_access = array (
					'agency_id' => $property->property_agent,
					'content_id' => $property_id,
					'link_type' => '1USR',
					'link_id' => $past_owner_id,
					'modified_time' => $date->toSql(),
					'block' => 1
				);
				$content_access[] = $tmp_access;
				$tmp_property_map = array (
					'agency_id' => $property->property_agent,
					'user_id' => $past_owner_id,
					'property_id' => $property_id,
					'active' => 0,
					'management_start' => $property->management_start,
					'ownership' => $property->ownership
				);
				$user_property_maps[] = $tmp_property_map;
			}
		}

		if (!array_diff($past_owner_ids, $future_owner_ids)) {
			Utilities::addTextLogs('BEFORE_PAST', 'nbs_activate_' . $future_id);
			Utilities::addTextLogs('NO_OWNER_CHANGES', 'nbs_activate_' . $future_id);
			return 0;
		}

		$past_item = (object)array();

		if ($current_item = $this->getCurrentItem($property_id)) {
			$past_item = $current_item;
		} else if ($current_item === false) {
			if (!$this->getError())
				$this->setError('Unable to load current item of property_id: ' . $property_id);
			return false;
		}

		$past_item->management_end = $date->toSql();
		$past_item->property_id = $property_id;
		$past_item->property_owner = $property->property_owner;
		$past_item->management_start = $property->management_start;
		$past_item->ownership = $property->ownership;
		$past_item->future_id = $future_id;
		$past_item->state = NBS_PAST;
		if (!$skip_backup = JArrayHelper::getValue($data, 'skip_backup'))
		{
			// Set property
			$past_item->property = json_encode($property);

			// Set property_data
			if ($last_property_data = $this->getPropertyData($property_id))
				$past_item->property_data = json_encode($last_property_data);

			if ($last_property_pool = $this->getPropertyPool($property_id))
				$past_item->property_pool = json_encode($last_property_pool);
			if ($this->getError())
				return false;

			// Set mortgage_account
			if ($last_mortgage_account = $this->getMortgageAccount($property_id))
				$past_item->mortgage_account = json_encode($last_mortgage_account);
			if ($this->getError())
				return false;
		}

		$tables = array();
		$tables[] = array ('table' => 'jentlacontent_access', 'key' => 'id', 'unique_keys' => array ('content_id', 'link_id', 'link_type'), 'data' => $content_access);
		$tables[] = array ('table' => 'user_property_map', 'key' => 'id', 'unique_keys' => array ('agency_id', 'user_id', 'property_id'), 'data' => $user_property_maps);
		$tables[] = array ('table' => 'nbs', 'key' => 'id', 'data' => array ($past_item));
		Utilities::addTextLogs('BEFORE_PAST', 'nbs_activate_' . $future_id);
		Utilities::addTextLogs($tables, 'nbs_activate_' . $future_id);
		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		if (!$saved_tables = $actionfieldModel->saveTables($tables)) {
			if (!$error = $actionfieldModel->getError())
				$error = 'Unable to save tables when markAsPast';
			$this->setError($error);
			return false;
		}
		Utilities::addTextLogs('AFTER_PAST', 'response_nbs_activate_' . $future_id);
		Utilities::addTextLogs($saved_tables, 'response_nbs_activate_' . $future_id);
		$past_id = null;
		foreach ($saved_tables as $saved_table) {
			$tablename = JArrayHelper::getValue($saved_table, 'table');
			if ($tablename == 'nbs') {
				$saved_items = JArrayHelper::getValue($saved_table, 'data');
				foreach ($saved_items as $saved_item)
					$past_id = $saved_item['id'];
			}
		}

		if ($past_id === null) {
			$this->setError('Invalid save response: ' . json_encode($saved_tables));
			return false;
		}

		return $past_id;
	}

	public function markAsCurrent($data)
	{
		$this->addLog(__METHOD__, 'Data: ' . json_encode($data), 'nbs_activate', false);

		if (!$pk = JArrayHelper::getValue($data, 'id')) {
			$this->setLogError(__METHOD__, 'Empty id not allowed to mark as active', 'nbs_activate', false);
			return false;
		}

		if (!$item = $this->getItem($pk)) {
			if (!$error = $this->getError())
				$error = 'Unable to find nbs item';
			$this->setLogError(__METHOD__, $error, 'nbs_activate', false);
			return false;
		}
		$this->addLog(__METHOD__, 'Data: ' . json_encode($item), 'nbs_activate', false);

		if (!$current_owner_ids = array_filter (explode(',', $item->property_owner))) {
			$this->setLogError(__METHOD__, 'Empty owners not allowed to activate the property', 'nbs_activate', false);
			return false;
		}

		if (!$property_id = $item->property_id) {
			$this->setLogError(__METHOD__, 'Unable to find property reference on active nbs: ' . $pk, 'nbs_activate', false);
			return false;
		}

		if (!$property = $this->getProperty(array ('property_id' => $property_id))) {
			if (!$this->getError())
				$this->setLogError(__METHOD__, 'Unable to load property: ' . $property_id, 'nbs_activate', false);
			return false;
		}

		if (!$skip_validate = JArrayHelper::getValue($data, 'skip_validate')) {
			if (!$this->canActivate($item)) {
				if (!$error = $this->getError())
					$error = 'NBS canActivate failed';
				$this->setLogError(__METHOD__, $error, 'nbs_activate', false);
				return false;
			}
		}

		$this->addLog(__METHOD__, 'ACTIVATE: ' . $pk, 'nbs_activate', false);
		if (!in_array($item->payment_system, array ('OP', 'RES')) && !Utilities::getDate($item->console_modified)) {
			$this->setLogError(__METHOD__, 'PENDING: STILL NOT SYNCED TO TRUST - ' . $pk, 'nbs_activate', false);
			return false;
		}

		$past_id = JArrayHelper::getValue($data, 'past_id', 0);
		if (!$skip_past = JArrayHelper::getValue($data, 'skip_past')) {
			$past_id = $this->markAsPast(array ('future_id' => $pk));
			if ($past_id === false)
				return false;
		}

		$past_property = null;
		if ($item->payment_system == 'RES' && $past_id > 0 && !$item->past_property_id)
		{
			$new_property = is_array($item->property) ? json_encode($item->property) : $item->property;
			$new_property = json_decode($item->property, true);
			$property_address2 = JArrayHelper::getValue($new_property, 'property_address2');
			$new_property['property_address2'] = $property_address2 . ' - NEW';
			$new_property['property_tenant'] = $property->property_tenant;
			unset($new_property['unique_id']);
			unset($new_property['pid']);
			unset($new_property['id']);
			if (!$this->saveProperty($new_property, $item->property_agent)) {
				if (!$error = $this->getError())
					$error = 'Unable to create new property for nbs: ' . $pk;
				$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_activate', false);
				return false;
			}
			if (!$property_id = JArrayHelper::getValue($new_property, 'id')) {
				$this->setLogError(__METHOD__, 'FAILED: Invalid response from save property for nbs: ' . $pk, 'nbs_activate', false);
				return false;
			}
			if ($property_id == $property->id) {
				$this->setLogError(__METHOD__, 'FAILED: Unable to generate new property for nbs: ' . $pk, 'nbs_activate', false);
				return false;
			}
			$item->past_property_id = $property->id;
			$item->property = json_encode($new_property);
			$item->property_id = $property_id;
			$property->id = $property_id;
			$past_property = array (
				'id' => $item->past_property_id,
				'property_address2' => $property_address2 . ' - SOLD',
				'inactive' => 1
			);
			Utilities::addTextLogs('REST_NEW: ' . $property_id . ' REST_PAST: ' . $item->past_property_id, 'nbs_activate', false);
		}

		$current_property = is_string($item->property) ? json_decode($item->property) : $item->property;
		$property->property_owner = $item->property_owner;
		$property->property_landlord = $item->property_owner;
		$property->ownership = $item->ownership;
		$property->let_only = $item->let_only;
		$property->management_start = $item->management_start;
		$property->management_review = $item->management_review;
		$property->property_expiry = '0000-00-00 00:00:00';
		$property->inactive = 0;
		$property_fields = array (
			'ppty_furnished', 'ppty_caraccomm', 'ppty_pets', 'ppty_bedrooms', 'ppty_bathrooms',
			'smokecode', 'gatecode', 'ppty_storage', 'default_payment_system', 'disbursement_type',
			'property_type', 'property_rental_type', 'ctp_water', 'plannum', 'planname', 'planaddr1', 'planaddr2', 'planaddr3', 'management_start', 'management_review', 'property_manager', 'bdm_ll_manager', 'sales_agent'
		);
		foreach ($property_fields as $property_field) {
			if (property_exists($current_property, $property_field))
				$property->$property_field = $current_property->$property_field;
		}

		$date = JFactory::getDate();
		$content_access = array();
		$user_property_maps = array();
		foreach ($current_owner_ids as $current_owner_id) {
			$tmp_access = array (
				'agency_id' => $property->property_agent,
				'content_id' => $property_id,
				'link_type' => '1USR',
				'link_id' => $current_owner_id,
				'modified_time' => $date->toSql(),
				'block' => 0
			);
			$content_access[] = $tmp_access;
			$tmp_property_map = array (
				'agency_id' => $property->property_agent,
				'user_id' => $current_owner_id,
				'property_id' => $property_id,
				'active' => 1
			);
			$user_property_maps[] = $tmp_property_map;
		}

		$property_data = json_decode($item->property_data);
		$property_data->property_id = $property_id;
		$property_data->lost_reason = '';
		$property_data->lost_note = '';
		$item->past_id = $past_id;

		$property_pool = json_decode($item->property_pool);
		$property_pool->property_id = $property_id;

		$rental_standards = json_decode($item->rental_standards);
		$disclosure = json_decode($item->disclosure);

		$properties = array ($property);
		if ($past_property)
			$properties[] = $past_property;

		if (($current_property->inactive == 1 && $current_property->default_payment_system == 'OP') || $current_property->default_payment_system == 'RES') {
			if ($withhold_id = Utilities::getSqlResult("SELECT `id` FROM #__property_withhold WHERE `property_id` = " . (int)$property_id . " AND `agency_id` = " . (int)$property->property_agent . " AND `deleted` = 0 AND `description` LIKE " . Utilities::Quote('%Withhold for MAA property ownership change%'), false)) {
				$property_withhold = array (
					'id' => $withhold_id,
					'deleted' => 1
				);
			} else {
				if (!$error = Utilities::getError())
					$error = 'Unable to find property withhold';
				$this->addLog(__METHOD__, 'Error to delete property withhold property_id: ' . $property_id . ' Error: ' . $error, 'nbs_activate', false);
			}
		}

		$tables = array();
		$tables[] = array ('table' => 'jentlacontent_access', 'key' => 'id', 'unique_keys' => array ('content_id', 'link_id', 'link_type'), 'data' => $content_access);
		$tables[] = array ('table' => 'user_property_map', 'key' => 'id', 'unique_keys' => array ('agency_id', 'user_id', 'property_id'), 'data' => $user_property_maps);
		$tables[] = array ('table' => 'property', 'key' => 'id', 'data' => $properties);
		$tables[] = array ('table' => 'property_data', 'key' => 'id', 'unique_keys' => array ('property_id'), 'data' => array ($property_data));
		$tables[] = array ('table' => 'property_pools', 'key' => 'id', 'unique_keys' => array ('property_id'), 'data' => array ($property_pool));
		$tables[] = array ('table' => 'nbs', 'key' => 'id', 'data' => array ($item));

		if ($withhold_id)
			$tables[] = array ('table' => 'property_withhold', 'key' => 'id', 'data' => array ($property_withhold));

		$user = JFactory::getUser();
		if (!$user->id) {
			if (!$rest_user = JArrayHelper::getValue($data, 'rest_user')) {
				$rest_user = !empty($item->signup_manager) ? $item->signup_manager : $property->property_agent;
			}
			Utilities::bindLogger($rest_user);
			$user = JFactory::getUser();
		}

		if (!$user_id = $user->get('id')) {
			$this->setLogError(__METHOD__, 'Empty user id not allowed to mark as current: ' . $pk, 'nbs_activate', false);
			return false;
		}
		$this->addLog(__METHOD__, 'User id: ' . $user_id, 'nbs_activate', false);

		Utilities::addTextLogs('BEFORE_ACTIVE', 'nbs_activate_' . $pk);
		Utilities::addTextLogs($tables, 'nbs_activate_' . $pk);
		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		if (!$saved_tables = $actionfieldModel->saveTables($tables)) {
			if (!$error = $actionfieldModel->getError())
				$error = 'Unable to save tables when markAsCurrent';
			$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_activate', false);
			return false;
		}
		Utilities::addTextLogs('AFTER_ACTIVE', 'response_nbs_activate_' . $pk);
		Utilities::addTextLogs($saved_tables, 'response_nbs_activate_' . $pk);

		$new_property_manager = $current_property->property_manager != $property->property_manager ? 1 : 0;
		$new_bdm_ll_manager = $current_property->bdm_ll_manager != $property->bdm_ll_manager ? 1 : 0;
		if ($new_property_manager || $new_bdm_ll_manager) {
			$update_roles['property_id'] = $property_id;
			$update_roles['property_agent'] = $property->property_agent;
			$this->addPropertyRoles($update_roles);
		}

		if (!$this->prepareTenantNotice(array('nbs_id' => $pk)))
			$this->addLog(__METHOD__, 'FAILED: Unable to send tenant notice for NBS: ' . $pk, 'nbs_activate', false);

		Utilities::loadApi();
		$ctp_water = array (
			'property_id' => $property->id,
			'origin' => 'nbs',
			'ctp_water' => $property->ctp_water
		);
		$leaseData = Models\Action::callApiMethod('commsLeaseWaterSave', $ctp_water, 0, 0, 1);

		if ($item->payment_system == 'OP')
		{
			if (!$this->updatePaymentPropertyMap(array('nbs_id' => $item->id)))
				return false;

			if ($mortgage_account = json_decode($item->mortgage_account, true))
			{
				if (empty($mortgage_account['user_id']))
					$mortgage_account['user_id'] = $current_owner_ids[0];
				if (empty($mortgage_account['property_id']))
					$mortgage_account['property_id'] = $item->property_id;
				if (!$this->saveMortgageAccount($mortgage_account, false)) {
					$this->setLogError(__METHOD__, 'FAILED: ' . $this->getError(), 'nbs_activate', false);
					return false;
				}
			}

			if (!$this->updatePropertyFees($property_id)) {
				if (!$error = $this->getError())
					$error = 'Unable to update property fees for nbs: ' . $pk;
				$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_activate', false);
				return false;
			}

			/*
			if (!Utilities::addJob(array ('property_id' => $property_id), 'manager', 'com_jentlacontent.nbsignup.updateWithHolds/site', 'NBS: UPDATE_WITHHOLD-' . $property_id)) {
				if (!$error = Utilities::getError())
					$error = 'Unable to add update-withhold job: ' . $property_id;
				$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_activate', false);
				return false;
			}
			*/
		}

		if ($item->payment_system == 'RES')
		{
			if (!$this->moveDocuments($pk, $property_id)) {
				if (!$error = $this->getError())
					$error = 'Unable to move docs to new property for nbs: ' . $pk;
				$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_activate', false);
				return false;
			}
		}

		if (!$this->saveDisbursementRules((array)$property)) {
			if (!$error = $this->getError())
					$error = 'Unable to save disbursement rules for nbs: ' . $pk;
				$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_activate', false);
				return false;
		}

		if (!$this->saveSharedProperties($pk)) {
			if (!$error = $this->getError())
				$error = 'Unable to save shared properties for nbs: ' . $pk;
			$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_activate', false);
			return false;
		}

		if (!$this->afterSubmitNBS($property_id)) {
			if (!$error = $this->getError())
				$error = 'Unable to delete mortgage invoice for nbs: ' . $pk . ', property_id: ' . $property_id;
			$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_activate', false);
			return false;
		}

		if ($rental_standards && ($property->property_state == 'VIC' || $property->property_state == 'QLD')) {
			jimport('jentla.rest');
			$layout = JRequest::getCmd('layout');
			$rental_standards->property_id = $property_id;
			$rental_standards->property_state = $property->property_state;
			$rental_standards->agency_id = $property->property_agent;
			$rental_standards->user_id = $user_id;
			$rental_standards->origin = 'N';
			if ($layout == 'landlord') {
				$rental_standards->llSiteLink = 1;
			}

			$rest = JRest::call('manager', 'com_jentlacontent.rentalstandards.saveRentalStandards/site', (array)$rental_standards);
			if ($rest_error = $rest->getError()) {
				$this->setError($rest_error);
				$this->addLog(__METHOD__, 'nbs_rentalstd_save_error:' . $rest->getError() , 'nbs_save_error', false);
			}
		}

		if ($disclosure && $property->property_state == 'VIC') {
			jimport('jentla.rest');
			$layout = JRequest::getCmd('layout');
			$disclosure->property_id = $property_id;
			$disclosure->property_state = $property->property_state;
			$disclosure->agency_id = $property->property_agent;
			$disclosure->user_id = $user_id;
			$disclosure->origin = 'N';
			$disclosure->updated_by = $user_id;
			if ($layout == 'landlord') {
				$disclosure->llSiteLink = 1;
			}

			$rest = JRest::call('manager', 'com_jentlacontent.disclosures.saveDisclosures/site', (array)$disclosure);
			if ($rest_error = $rest->getError()) {
				$this->setError($rest_error);
				$this->addLog(__METHOD__, 'nbs_disclosure_save_error:' . $rest->getError() , 'nbs_save_error', false);
			}
		}

		$this->addLog(__METHOD__, 'SUCCESS: ' . $pk, 'nbs_activate', false);

		return true;
	}

	public function getItemsForActivate()
	{
		$db		= $this->_db;
		$query	= $db->getQuery(true);
		$query->select('nbs.id');
		$query->from('#__nbs AS nbs');
		$query->where('nbs.state IN (' . implode(',', array (NBS_INCOMPLETE, NBS_COMPLETED)) . ')');
		$query->where('nbs.past_id = -1');
		$query->where('nbs.management_start <=' . $db->q(JHtml::date('now', 'Y-m-d')));
		$query->where('nbs.payment_system != \'OP\'');
		$db->setQuery((string)$query);
		$items = $db->loadObjectList();

		// Check for a database error.
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		$this->addLog(__METHOD__, $query->dump(), 'nbs_activate', false);

		return $items;
	}

	public function activate($data)
	{
		if ($pk = JArrayHelper::getValue($data, 'id')) {
			if (!$this->markAsCurrent($data)) {
				if (!$error = $this->getError())
					$error = 'Unable to activate property';
				Utilities::outmsg($error, 'error');
			}
			die($pk . ' - Item activated successfully.');
		}

		if (!$items = $this->getItemsForActivate()) {
			if ($error = $this->getError())
				Utilities::outmsg($error, 'error');
			die('No items have to activate');
		}

		if (JArrayHelper::getValue($data, 'print_only')) {
			print_r($items);
			die();
		}

		foreach ($items as $item) {
			$tmp_data = array_merge($data, (array)$item);
			if (!Utilities::addJob($tmp_data, 'node', 'com_jentlacontent.nbsignup.markAsCurrent/site', 'Mark as Current: ' . $item->id)) {
				if (!$error = Utilities::getError())
					$error = 'Unable to addjob for item - ' . $item->id;
				Utilities::outmsg($error, 'error');
			}
		}

		die('Done');
	}

	public function activateItems($data)
	{
		if (!$items = $this->getItemsForActivate())
		{
			if ($error = $this->getError()) {
				$this->setLogError(__METHOD__, $error, 'nbs_activate', false);
				return false;
			}
			$this->addLog(__METHOD__, 'NO_ITEMS', 'nbs_activate', false);
			return true;
		}

		foreach ($items as $item)
		{
			$tmp_data = array_merge($data, (array)$item);
			if (!Utilities::addJob($tmp_data, 'node', 'com_jentlacontent.nbsignup.markAsCurrent/site', 'Mark as Current: ' . $item->id))
			{
				if (!$error = Utilities::getError())
					$error = 'Unable to addjob for item - ' . $item->id;
				$this->setLogError(__METHOD__, $error, 'nbs_activate', false);
			}
		}

		return true;
	}

	public function getAnalytics($item,$steps)
	{
		//User wise Analytics
		$db = $this->getDbo();
		$query	= $db->getQuery(true);
		$query->select('user_id,source,sum(`interval`) as total_time');
		$query->from('#__nbs_analytics');
		$query->group('user_id,source');
		$query->where('nbs_id=' . $item->id);

		$db->setQuery($query);
		$analytics = $db->loadAssocList();

		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		// Get unique users
		$users = JArrayHelper::getColumn($analytics,'user_id');
		$users = array_unique($users);

		// Prepare steps template title
		$stepsTitle = array();
		foreach($steps as $step){
			if($step['template']=='intro' || $step['template']=='thanks'){
				continue;
			}
			else if($step['title']=='')
				$step['title'] = $step['template'];

			$stepsTemplate[] = $step['template'];
			$stepsTitle[] = $step['title'];
		}

		// Prepare analytics map
		$analyticsMap = array();
		foreach($analytics as $analytic){
			$analyticsMap[$analytic['user_id']][$analytic['source']] = (int)$analytic['total_time'];
		}

		//Average Analytics
		$query	= $db->getQuery(true);
		$query->select('source,AVG(`interval`) as total_time');
		$query->from('#__nbs_analytics');
		$query->group('source');
		$query->where('agent_id=' . $item->property_agent);

		$db->setQuery($query);
		$analyticsAverage = $db->loadAssocList();

		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		// Prepare Average analytics map
		$analyticsAverageMap = array();
		foreach($analyticsAverage as $analyticAvg){
			$analyticsAverageMap[$analyticAvg['source']] = (int)$analyticAvg['total_time'];
		}


		$arrSeries = array();
		$arrSeriesFinal = array();

		$arrSeries['name'] = 'Average';
		$arrSeries['color'] = '#2f7ed8';

		$arrSeriesDataAverage = array();
		foreach($stepsTemplate as $stepTemplate){
			$arrSeriesDataAverage[] = $analyticsAverageMap[$stepTemplate];
		}

		$arrSeries['data'] = $arrSeriesDataAverage;
		$arrSeriesFinal[] = $arrSeries;

		// Prepare user map
		$query	= $db->getQuery(true);
		$query->select('id,fullname');
		$query->from('#__jentlausers');
		$query->where('id in(' .  implode(',', $users) . ')');

		$db->setQuery($query);
		$user_list = $db->loadAssocList();
		$userMap = array();
		foreach($user_list as $user){
			$userMap[$user['id']] = $user['fullname'];
		}

		foreach($users as $user){

			$arrSeries = array();
			$arrSeries['name'] = $userMap[$user];
			$arrSeries['color'] = '#c42525';

			$arrSeriesTime = array();

			foreach($stepsTemplate as $stepTemplate){
				$arrSeriesTime[] = $analyticsMap[$user][$stepTemplate];
			}

			$arrSeries['data'] = $arrSeriesTime;
			$arrSeriesFinal[] = $arrSeries;

		}

		return array('categories' => $stepsTitle, 'series' => $arrSeriesFinal);
	}

	public function saveAnalytics($data)
	{
		$user = JFactory::getUser();
		if ($post_in_data = JArrayHelper::getValue($data, 'data'))
			$data = $post_in_data;

		if (!$user_id = $user->get('id')) {
			$this->setError('Empty user not allowed to load');
			return false;
		}

		if (!$nbs_id = JArrayHelper::getValue($data, 'nbs_id')) {
			$this->setError('Empty nbs_id not allowed');
			return false;
		}

		$start = JArrayHelper::getValue($data, 'start');
		$finish = JArrayHelper::getValue($data, 'finish');

		$data_nbs = $this->getItem($nbs_id);
		$data['agent_id'] = $data_nbs->property_agent;
		$data['user_id'] = $user_id;
		$data['interval'] = strtotime($finish)-strtotime($start);

		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		if (!$actionfieldModel->saveItem($data, 'nbs_analytics')) {
			$this->setError($actionfieldModel->getError());
			return false;
		}
		return true;
	}

	public function sendUnSignedReminders()
	{
		$today = JRequest::getVar('date','now');
		$two_days_before   = JFactory::getDate($today)->modify('-2 day')->format('Y-m-d 23:00:00');
		$three_days_before = JFactory::getDate($today)->modify('-3 day')->format('Y-m-d 23:00:00');
		$id = JRequest::getVar('nbs_id', 0);

		$db = $this->_db;
		$query = $db->getQuery(true);
		$query->select('nb.*');
		$query->from('#__nbs AS nb');
		if (empty($id)) {
			$query->where('nb.pm_requested>=' . $db->q($three_days_before));
			$query->where('nb.pm_requested<=' . $db->q($two_days_before));
			$query->where('nb.state=1');
		}
		else {
			$query->where('nb.id IN ('.$id.')');
		}
		$db->setQuery($query);
		$items = $db->loadAssocList();

		$this->addLog(__METHOD__, $query->dump(), 'nbs_reminder', false);

		// Check for a database error.
		if ($db->getErrorNum()) {
			$this->setLogError(__METHOD__, $db->getErrorMsg(), 'nbs_reminder', false);
			return false;
		}

		foreach ($items as $item)
		{
			$pk = JArrayHelper::getValue($item, 'id');
			$rest_user = JArrayHelper::getValue($item, 'signup_manager');
			$item['rest_user'] = $rest_user;
			if (!Utilities::addJob($item, 'node', 'com_jentlacontent.nbsignup.sendUnSignedReminder/site', 'REMINDER: NBS-UNSIGN-TO-PM - ' . $pk)) {
				if (!$error = Utilities::getError())
					$error = 'Unable to add job: ' . $pk;
				$this->setLogError(__METHOD__, $error, 'nbs_reminder', false);
			}
		}

		return true;
	}

	public function sendUnSignedReminder($data)
	{
		if ($rest_user = JArrayHelper::getValue($data, 'rest_user'))
			Utilities::bindLogger($rest_user);

		if (!$nbs_id = JArrayHelper::getValue($data, 'id')) {
			$this->setLogError(__METHOD__, 'Empty nbs_id not allowed', 'nbs_reminder', false);
			return false;
		}

		$db = $this->_db;
		if (!$receiver = JArrayHelper::getValue($data, 'signup_manager')) {
			$this->setLogError(__METHOD__, 'Empty receiver not allowed', 'nbs_reminder', false);
			return false;
		}

		$append = array();
		$ll_open_mail = JArrayHelper::getValue($data, 'll_email_seen');
		if ($ll_open_mail == '0000-00-00 00:00:00')
			$append['pm_request_ll_status'] = 'They have not opened the email yet.';
		else {
			$db->setQuery('SELECT * FROM #__nbs_analytics WHERE nbs_id = ' . $nbs_id);
			$nbs_analytics = $db->loadResult();
			if (empty($nbs_analytics))
				$append['pm_request_ll_status'] = 'They have not opened the form yet.';
			else
				$append['pm_request_ll_status'] = 'They opened it on ' . JHtml::date($ll_open_mail, 'd/m/Y') . '.';
		}
		$attachement = array();
		if (!empty($nbs_analytics))
		{
			$this->addLog(__METHOD__, 'PREPARE: REMINDER-UNSIGNED-LL-TO-PM - ' . $nbs_id, 'nbs_reminder', false);
			$type_id = JArrayHelper::getValue($data, 'business_type');
			$main_attachement = $this->prepareHTML(array (
				'id' => $nbs_id,
				'type_id' => $type_id,
				'tmpl' => 'blank',
				'layout' => 'landlord_analytic'
			));
			$append['pm_request_ll_status'] .= " See the attachment for detail about how long they have spent on each page of the form.";
			$report_html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">';
			$report_html .= $main_attachement;
			$attachement['wkhtml_attachement'] = array (
				array (
					'content' => $report_html,
					'path'	  => 'images/attachments/signups'.DS.$nbs_id.DS.JHtml::date('now','Y-m-d')
					)
			);
		}

		$action = array ('inline_attach_site' => 'propertymanager');
		$agencyModel = JModel::getInstance('AgencyModel', '', array('ignore_request' => true));
		if (!$agencyModel->sendMailWithContentType($nbs_id,'nbsignup',$receiver,'nbs_not_signed_ll_email', $action,'', $append, $attachement,'responsive_agency_cover')) {
			$this->setLogError(__METHOD__, $agencyModel->getError(), 'nbs_reminder', false);
			return false;
		}

		$this->addLog(__METHOD__, 'SUCCESS: REMINDER-UNSIGNED-LL-TO-PM - ' . $nbs_id, 'nbs_reminder', false);
		return true;
	}
	public function processNBSRenewals()
	{
		$db = $this->_db;
		$query = $db->getQuery(true);
		$query->select('id, property_id');
		$query->from('#__nbs');
		$query->where('management_start <= CURDATE() AND past_id = 3');
		$db->setQuery($query);
		$newItems = $db->loadObjectList();
		foreach ($newItems as $newItem) {
			$query = $db->getQuery(true);
			$query->select('id');
			$query->from('#__nbs');
			$query->where('property_id = ' . (int)$newItem->property_id);
			$query->where('past_id != 3');
			$db->setQuery($query);
			$oldItem = $db->loadObject();
			if ($oldItem) {
				$this->markAsInactive($oldItem);
			}
			$this->markAsActive($newItem);
		}
		return true;
	}
	public function markAsActive($data)
	{
		$update = array('id' => $data->id, 'past_id' => 0); // Set past_id to 0 for activation
		$tables = array('table' => 'nbs', 'key' => 'id', 'data' => array($update));
		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		if (!$actionfieldModel->saveTable($tables)) {
			$this->setError($actionfieldModel->getError());
			return false;
		}
		return true;
	}
	public function markAsInactive($data)
	{
		$update = array('id' => $data->id, 'state' => -1); // Set state to -1 for deactivation
		$tables = array('table' => 'nbs', 'key' => 'id', 'data' => array($update));
		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		if (!$actionfieldModel->saveTable($tables)) {
			$this->setError($actionfieldModel->getError());
			return false;
		}
		return true;
	}
	public function dutch_flag_aglorithm(){
		$arr = array(1,2,2,2,1,1,0,1,0,0,2,0,1);
		$zero = 0;
		$one = 0;
		$two = 0;
		for($i=0;$i<count($arr);$i++){
			if($arr[$i] == 0){
				$zero++;
			}
			if($arr[$i] == 1){
				$one++;
			}
			if($arr[$i] == 2){
				$two++;
			}
		}
		for($i=0;$i<$zero;$i++){
			$arr[$i] = 0;
		}
		for($i=$zero;$i<$zero+$one;$i++){
			$arr[$i] = 1;
		}
		for($i=$zero+$one;$i<count($arr);$i++){
			$arr[$i] = 2;
		}
		print_r($arr);exit;
	
	}
	// public function formatText($text, $lineLength) {
	// 	$words = explode(" ", $text);
	// 	$line = "";

	// 	foreach ($words as $word) {
	// 		if (strlen($line) + strlen($word) + 1 > $lineLength) {
	// 			echo trim($line) . "\n"; // Print the current line
	// 			$line = ""; // Reset line
	// 		}
	// 		$line .= $word . " ";
	// 	}

	// 	if (!empty(trim($line))) { // Print remaining text
	// 		echo trim($line) . "\n";
	// 	}
	// }

	public function updatePropertyFees($property_id)
	{
		if (empty($property_id)) {
			$this->setError('Unable to find property_id on afterSubmit');
			return false;
		}

		$user = JFactory::getUser();
		$post = array ( 'property_id' => $property_id, 'rest_user' => $user->get('id') );
		if (!Utilities::addJob($post, 'manager', 'com_jentlacontent.nbsignup.updatePropertyFees/site', 'NBS: UPDATE_FEES' . $property_id)) {
			if (!$error = Utilities::getError())
				$error = 'Unable to add update-fees job: ' . $property_id;
			$this->setError($error);
			return false;
		}

		return true;
	}

	public function isDocumentUploaded()
	{
		if (!$file = JRequest::getVar('Filedata', '','files')) {
			$this->setError('Empty file data.');
			return false;
		}
		if (!$nbs_id = JRequest::getInt('id')) {
			$this->setError('Empy nbs_id not allowed.');
			return false;
		}
		if (isset($file['error']) && $file['error']==1) {
			$this->setError('File have some error.');
			return false;
		}
		if (empty($file['name'])) {
			$this->setError('Empty file name not allowed.');
			return false;
		}
		if (!$site_id = JRequest::getInt('site_id')) {
			$this->setError('Empty site id not allowed.');
			return false;
		}
		$item = $this->getItem($nbs_id);
		//change uploaded filename
		$format = strtolower(JFile::getExt($file['name']));
		$file_name = 'nbs_'.$nbs_id.'_'.JFactory::getDate('now')->format('Y-m-d_His').'.'.$format;
		$file['name']		= JFile::makeSafe($file_name);
		$file['filepath']	= JPath::clean('images/signups/'.$nbs_id.DS.JHtml::date('now','Y-m-d').DS.$file['name']);

		jimport('joomla.filesystem.file');
		if ($file['name'] !== JFile::makesafe($file['name'])) {
			$this->setError(JText::_('COM_MEDIA_ERROR_WARNFILENAME'));
			return false;
		}
		$detectedType = exif_imagetype($file['tmp_name']);
		if ($format == '' || $format == false || (!in_array($format, array('pdf')) && !in_array($detectedType, array(2,3)))) {
			$this->setError('Document format not supported');
			return false;
		}
		if (!JFile::upload($file['tmp_name'], $file['filepath'])) {
			$this->setError('Unable to upload the document');
			return false;
		}
		$user = JFactory::getUser();
		$data	= array (
			'path'			=> $file['filepath'],
			'site_id'		=> $site_id,
			'list_id'	=> $nbs_id,
			'content_subtype'=> 'main',
			'content_type'	=> 'nbs',
			'property_id'	=> $item->property_id,
			'property_owner'=> $item->property_owner,
			'property_agent'=> $item->property_agent,
			'user_id'		=> $user->id
		);

		// Do manager actions.
		jimport('jentla.rest');
		$rest	= JRest::call('manager', 'com_jentlacontent.nbsignup.saveUploadDocument/site', $data);
		if ($rest_error = $rest->getError()) {
			$this->setError($rest_error, 'error');
			return false;
		}
		if (!$response = $rest->getResponse(true)) {
			$this->setError('Empty response from document upload action.', 'error');
			return false;
		}

		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		foreach ($response as $table) {
			if (!$actionfieldModel->update($table)) {
				if (!$error = $actionfieldModel->getError())
					$error = 'Save Table failed on upload';
				$this->setError($error);
				return false;
			}
		}

		return true;
	}

	public function uploadDocument()
	{
		$data = JRequest::getVar('data', '');
		if (empty($data)) {
			$this->setError('Empty data not allowed');
			return false;
		}

		if (is_string($data))
			$data = json_decode($data, true);

		if (!$id = JArrayHelper::getValue($data, 'id')) {
			$this->setError('Unable to find NBS reference on upload data save.');
			return false;
		}

		if (!$this->isDocumentUploaded()) {
			if(!$error = $this->getError())
				$error = 'Document uploaded failed';
			$this->setError($error);
			$this->addLog(__METHOD__, 'Error on upload ' .$error, 'nbs_punt_failed', false);
			return false;
		}

		if ($data['initial_signed'] == '0000-00-00 00:00:00')
			$data['initial_signed'] = JFactory::getDate()->toSql();
		$data['state'] = 3;
		$data['manually_signed'] = JFactory::getDate()->toSql();
		$data['completed_origin'] = 'Upload';

		if (!$this->save($data)) {
			if(!$error = $this->getError())
				$error = 'Upload data save failed';
			$this->setError($error);
			return false;
		}

		if (!$this->afterComplete($id)) {
			$this->setError('Error on after upload');
			return false;
		}

		return true;
	}

	protected function getDocuments($pk)
	{
		if (empty($pk)) {
			$this->setError('Empty signup-id not allowed to load documents');
			return false;
		}

		$docsModel = JModel::getInstance('OurtradieDocs', 'JentlaContentModel', array('ignore_request' => true));
		$docsModel->setState('filter.list_type', 'nbs');
		$docsModel->setState('filter.list_id', $pk);
		if (!$documents = $docsModel->getItems()) {
			$this->setError($docsModel->getError());
			return false;
		}

		return $documents;
	}

	public function restMoveDocuments($data)
	{
		if (!$pk = JArrayHelper::getValue($data, 'id')) {
			$this->setError('Empty signup id not allowed');
			return false;
		}

		if (!$property_id = JArrayHelper::getValue($data, 'property_id')) {
			$this->setError('Empty property_id not allowed');
			return false;
		}

		return $this->moveDocuments($pk, $property_id);
	}

	public function moveDocuments($pk, $property_id)
	{
		$documents = $this->getDocuments($pk);
		if ($this->getError())
			return false;

		if (!$documents)
			return true;

		$move_documents = array ();
		foreach ($documents as $document) {
			$document->property_id = $property_id;
			$document->origin = 'N';
			$move_documents[] = (array)$document;
		}

		if (!$this->saveDocuments($move_documents)) {
			if (!$this->getError())
				$this->setError('Unable to save documents. nbs_id:' . $pk . ' property_id:' . $property_id);
			return false;
		}

		return true;
	}

	public function markAsFailed($data)
	{
		if ($post_in_data = JArrayHelper::getValue($data, 'data'))
			$data = $post_in_data;

		if (!$nbs_id = JArrayHelper::getValue($data, 'nbs_id')){
			$this->setError('Empty nbs id doesn\'t allowed to make punt and failed');
			return false;
		}

		$nbs_data = $this->getItem($nbs_id);
		if ($this->getError())
			return false;
		if (!$property_id = $nbs_data->property_id) {
			$this->setError('Empty property id doesn\'t allowed to make punt and failed');
			return false;
		}
		//yoyo
		$taskData = array();
		$taskData['context'] = 'NBSU';
		$taskData['context_id'] = $nbs_data->id;
		$taskData['user_ids'] = $nbs_data->property_owner;
		if (!Utilities::addJob($taskData, 'manager', 'com_jentlacontent.todotasks.cancelTodoTask/site', 'NBS: Cancel Todo ' . __METHOD__ . $nbs_id)) {
			if (!$error = Utilities::getError())
				$error = 'Unable to cancel Todo task for NBS: ' . $nbs_id;
			$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_cancel_todo', false);
			return false;
		}

		$tables = array();
		$nbs['id'] = $nbs_id;
		$nbs['state'] = NBS_PUNT_FAILED;
		$tables[] = array ('table' => 'nbs', 'key' => 'id', 'data' => array ($nbs));
		if ($nbs_data->past_id > -1) {
			if ($removeProperty = JArrayHelper::getValue($data, 'removeProperty')) {
				$db	= $this->getDbo();
				$query = $db->getQuery(true);
				$query->select('content_id, link_id, link_type');
				$query->from('#__jentlacontent_access');
				$query->where('content_id = ' . $property_id);

				// Setup the query
				$db->setQuery($query);
				$content_access = $db->loadAssocList();
				if ($db->getErrorNum()) {
					$this->setError($db->getErrorMsg());
					return false;
				}
				foreach($content_access as &$content)
					$content['block'] = 1;

				$query = $db->getQuery(true);
				$query->select('user_id, property_id, agency_id, 1 AS block');
				$query->from('#__shared_properties');
				$query->where('property_id = ' . $property_id .' AND agency_id=' .$nbs_data->property_agent .' AND user_id IN (' .$nbs_data->property_owner .')');

				// Setup the query
				$db->setQuery($query);
				$shared_properties_users = $db->loadAssocList();
				if ($db->getErrorNum()) {
					$this->setError($db->getErrorMsg());
					return false;
				}

				if (!empty($shared_properties_users))
					$tables[] = array ('table' => 'shared_properties', 'key' => 'id', 'unique_keys' => array ('property_id', 'agency_id', 'user_id'), 'data' => $shared_properties_users);
				$tables[] = array ('table' => 'jentlacontent_access', 'key' => 'id', 'unique_keys' => array ('content_id', 'link_id', 'link_type'), 'data' => $content_access);

				$property_data['id'] = $property_id;
				$property_data['inactive'] = 1;
				$tables[] = array ('table' => 'property', 'key' => 'id', 'data' => array ($property_data));
			}
		}

		if ($removeWithhold = JArrayHelper::getValue($data, 'removeWithhold')) {
			if ($withhold_id = JArrayHelper::getValue($data, 'withhold_id')) {
				$withold = array();
				$withold['id'] = $withhold_id;
				$withold['deleted'] = 1;
				$tables[] = array ('table' => 'property_withhold', 'key' => 'id', 'data' => array ($withold));
			}
		}

		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		if (!$saved_tables = $actionfieldModel->saveTables($tables)) {
			if (!$error = $actionfieldModel->getError())
				$error = 'Unable to save tables when markPuntFailed';
			$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_punt_failed', false);
			return false;
		}
		return true;
	}

	public function revertFailed($data)
	{
		if ($post_in_data = JArrayHelper::getValue($data, 'data'))
			$data = $post_in_data;

		if (!$nbs_id = JArrayHelper::getValue($data, 'nbs_id')){
			$this->setError('Empty nbs id doesn\'t allowed to make punt and failed');
			return false;
		}
		$this->addLog(__METHOD__, ' to NBS: ' .$nbs_id, 'nbs_punt_failed', false);
		$nbs_data = $this->getItem($nbs_id);
		if ($this->getError())
			return false;
		if (!$property_id = $nbs_data->property_id) {
			$this->setError('Empty property id doesn\'t allowed to make punt and failed');
			return false;
		}

		$db	= $this->getDbo();
		$query = $db->getQuery(true);
		$query->select('id, property_address1, property_postcode, unique_id, property_agent');
		$query->select('CONCAT_WS('. $query->quote(',') .', property_owner, property_manager, property_agent) as proprety_links');
		$query->from('#__property');
		$query->where('id=' . $db->q($property_id));

		// Setup the query
		$db->setQuery($query);
		$property = $db->loadAssoc();
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		if (!$this->validateProperty(array ('property' => $property))) {
			if ($error = $this->getError())
				$error = 'Validate property failed in revert';
			$this->addLog(__METHOD__, 'FAILED: ' . $error, 'nbs_punt_failed', false);
			return false;
		}

		$tables = array();

		$nbs['id'] = $nbs_id;
		$nbs['state'] = NBS_DRAFT;

		$property_data['id'] = $property_id;
		$property_data['inactive'] = 0;

		$tables[] = array ('table' => 'nbs', 'key' => 'id', 'data' => array ($nbs));
		$tables[] = array ('table' => 'property', 'key' => 'id', 'data' => array ($property_data));

		if ($nbs_data->past_id > -1) {
			$update_roles['property_id'] = $property_id;
			$update_roles['property_agent'] = $nbs_data->property_agent;

			if (!$this->addPropertyRoles($update_roles)) {
				if (!$error = $this->getError())
					$error = 'Unable to update property roles for: ' . $property_id;
				$this->addLog(__METHOD__, 'FAILED: ' . $error, 'nbs_punt_failed', false);
				return false;
			}
		}

		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		if (!$saved_tables = $actionfieldModel->saveTables($tables)) {
			if (!$error = $actionfieldModel->getError())
				$error = 'Unable to save tables when markPuntFailed';
			$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_punt_failed', false);
			return false;
		}
		return true;
	}

	protected function getPaymentName($ageny_trust_name)
	{
		$ageny_trust_name = empty($ageny_trust_name) ? '' : strtolower($ageny_trust_name);
		$payments = array(
			'ourproperty' => 'OP',
			'rpo' => 'RPO',
			'console' => 'CON',
			'propertyme' => 'PME',
			'rest' => 'RES'
		);

		if($ageny_trust_name && isset($payments[$ageny_trust_name]))
			return $payments[$ageny_trust_name];
		return $ageny_trust_name;
	}

	public function createLLUniqueId($ll_id, $agent_id)
	{
		return 'L-' .$agent_id. '-CID-OP' .$ll_id;
	}

	public function createLLRefId($unique_id, $agent_id)
	{
		$ref = '';
		if (strpos($unique_id, 'L-' .$agent_id . '-') !== false)
			$ref = str_replace('L-' .$agent_id . '-', '', $unique_id);
		return $ref;
	}

	public function onJentlaFormAccess($pk)
	{
		$user = JFactory::getUser();
		if (!$user_id = $user->get('id')) {
			$this->setError('Empty user id not allowed to load form');
			return false;
		}

		if (!JentlaContentHelperOurTradie::isPMGroup())
		{
			if (empty($pk)) {
				JError::raiseError(500, 'Empty content id not allowed');
				return false;
			}

			if (!$item = $this->getItem($pk)) {
				JError::raiseError(500, 'Empty data not allowed');
				return false;
			}

			$owners = explode(',', $item->property_owner);
			if (!in_array($user->get('id'), $owners)) {
				JError::raiseError(500, 'You don\'t have rights to access this form');
				return false;
			}
		}

		return true;
	}

	public function onJentlaFormAfterSave($data)
	{
		$user = JFactory::getUser();
		if (!$user_id = $user->get('id')) {
			$this->setError('Unable to load user id');
			return false;
		}

		if (!$id = JArrayHelper::getValue($data, 'content_id')) {
			$this->setError('Unable to load content id');
			return false;
		}

		if (!$data = JArrayHelper::getValue($data, 'data')) {
			$this->setError('Unable to load data of the form');
			return false;
		}

		if (!$item = $this->getItem($id)) {
			$this->setError('Unable to load nbs: ' .$id);
			return false;
		}

		if(!$property_id = $item->property_id) {
			$this->setError('Unable to property id for nbs: ' .$id);
			return false;
		}

		if (!$nbsManagerId = $item->signup_manager) {
			$this->setError('Unable to load from user');
			return false;
		}
		$di_letter = JArrayHelper::getValue($data, 'di_letter', null);
		if (!is_null($di_letter) && $item->past_id > -1) {
			$property = array();
			$property['id'] = $property_id;
			$property['di_letter'] = $di_letter;
			$tables = array();
			$tables[] = array ('table' => 'property', 'key' => 'id', 'data' => array ($property));
			$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
			if (!$saved_tables = $actionfieldModel->saveTables($tables)) {
				if (!$error = $actionfieldModel->getError())
					$error = 'Unable to save tables when SA compliance save.';
				$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_compliance', false);
				return false;
			}
		}

		$agencyModel = JModel::getInstance('AgencyModel', '', array('ignore_request' => true));
		if (!$smokeAlarm = $agencyModel->loadTemplate('sa_compliance_report')) {
			$this->setError($agencyModel->getError());
			return false;
		}

		$data['signature_date'] = Utilities::getDate($data['signature_date'], 'd/m/Y');
		if (!is_null($data['di_letter'])) {
			$data['our_di'] = $data['di_letter'] == 1 ? 'checked' : '';
			$data['our_di_later'] = $data['di_letter'] == 2 ? 'checked' : '';
			$data['own_di'] = $data['di_letter'] == 0 ? 'checked' : '';
		}

		if (!$report = $agencyModel->getTemplateWithContentType($id, $smokeAlarm->content_type, $smokeAlarm->name, $data)) {
			$this->setError($agencyModel->getError());
			return false;
		}

		if (empty($report->content)) {
			$this->setError('Unable to prepare report content for nbs: ' . $id);
			return false;
		}

		$attachement['wkhtml_attachement'] = array (
			array (
				'content' => $report->content,
				'path'	  => 'images/attachments/signups'.DS.$id.DS.JHtml::date('now','Y-m-d'),
				'version' => array (
					'user_id' => $user_id,
					'content_id' => $id,
					'content_type' => 'nbs',
					'content_subtype' => $smokeAlarm->name,
					'status' => 3
				)
			)
		);

		$action = array ('inline_attach_site' => 'propertymanager');
		if (!$sent = $agencyModel->sendMailWithContentType($id, $smokeAlarm->content_type, $nbsManagerId, 'sa_compliance', $action,  '', array(), $attachement,'responsive_agency_cover')) {
			$this->setError($agencyModel->getError());
			return false;
		}

		return true;
	}

	public function afterSaveNBS($data)
	{
		if (!$property = JArrayHelper::getValue($data, 'property')) {
			$this->setError('Unable to load property details');
			return false;
		}

		if (!$property_agent = JArrayHelper::getValue($property, 'property_agent')) {
			$this->setError('Unable to load your property agent');
			return false;
		}

		if (!$property_id = JArrayHelper::getValue($data, 'property_id')) {
			$this->setError('Unable to find property reference');
			return false;
		}

		if ($data['past_id'] == -1) {
			if (!Utilities::getSqlResult("SELECT COUNT(`id`) FROM #__property_withhold WHERE `property_id` = " . (int)$property_id . " AND `agency_id` = " . (int)$property_agent . " AND `description` LIKE " . Utilities::Quote('%Withhold for MAA property ownership change%'), false)) {
				if ($error = Utilities::getError()) {
					$this->setError($error);
					return false;
				}
				$post = array ( 'property_id' => $property_id, 'property_agent' => $property_agent );
				if (!Utilities::addJob(array($post), 'manager', 'com_jentlacontent.nbsignup.saveWithHolds/site', 'NBS: UPDATE_WITHHOLD' . $property_id)) {
					if (!$error = Utilities::getError())
						$error = 'Unable to add withhold job: ' . $property_id;
					$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_activate', false);
					return false;
				}
			}
		}

		return true;
	}

	public function addPropertyRoles($data)
	{
		if ( empty($data) )
			$post = JRequest::get('POST');
			if ($post_in_data = JArrayHelper::getValue($post, 'data'))
				$data = $post_in_data;

		if ( empty($data) ) {
			$this->setError('Empty data not allowed to update property roles');
			return false;
		}

		$data = (object)$data;

		if (!$data->property_id) {
			$this->setError('Empty property_id not allowed to update property roles');
			return false;
		}

		if (!$data->property_agent) {
			$this->setError('Empty agency_id not allowed to update property roles');
			return false;
		}

		$update_roles = array (
			'property' => $data->property_id,
			'agency_id' => $data->property_agent,
			'change_role' => 1,
			'restricted_roles' => array ('app_roles' => 0, 'viewing_roles' => 0)
		);
		if (!Utilities::addJob($update_roles, 'manager', 'com_jentlacontent.import.UpdatePropertyRoles/site', 'NBS: UPDATE_PROPERTY_ROLES ' . __METHOD__ . $data->property_id)) {
			if (!$error = Utilities::getError())
				$error = 'Unable to update property roles for: ' . $data->property_id;
			$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_update_roles', false);
			return false;
		}

		return true;
	}

	protected function saveSharedProperties($id)
	{
		if (!$id) {
			if (!$id = JRequest::getInt('id')) {
				$this->setError('Empy nbs_id not allowed to after complete process.');
				return false;
			}
		}

		if (!$nbs = $this->getItem($id)) {
			if (!$error = $this->getError())
				$error = 'Unable to find nbs item';
			$this->setLogError(__METHOD__, $error, 'nbs_aftercomplete', false);
			return false;
		}

		if (!$property_id = $nbs->property_id) {
			$this->setLogError(__METHOD__, 'Unable to find property reference for: ' . $id, 'nbs_aftercomplete', false);
			return false;
		}

		if (!$property = json_decode($nbs->property)) {
			$this->setLogError(__METHOD__, 'Unable to find property data for: ' . $id, 'nbs_aftercomplete', false);
			return false;
		}

		$valid_disbursement_type = array(5,6,7,9,10);
		if (!in_array($property->disbursement_type, $valid_disbursement_type)) {
			Utilities::addLogs('Share property save skip for nbs id: ' .$id .' ; Disbursement type is: ' .$property->disbursement_type, 'nbs_aftercomplete',true,'front');
			return true;
		}

		// current property actions
		if ($nbs->past_id > -1) {
			$user = JFactory::getUser();
			if (!Utilities::addJob(array('property_id' => $property_id, 'rest_user' => $user->get('id')), 'manager', 'com_jentlacontent.nbsignup.saveSharedProperties/site', 'NBS: saveSharedProperties' . $property_id)) {
				if (!$error = Utilities::getError())
					$error = 'Unable to add saveSharedProperties job: ' . $property_id;
				$this->setLogError(__METHOD__, 'Error ' . $error, 'nbs_aftercomplete', false);
				return false;
			}
		}

		return true;
	}

	public function getFees($id, $for_user_group = null)
	{
		if (!$id) {
			if (!$id = JRequest::getInt('id')) {
				$this->setError('Empy nbs_id not allowed to load fees.');
				return false;
			}
		}

		$db		= $this->getDbo();
		$query	= $db->getQuery(true);
		$query->select('name, value, unit, text_prepend, text_append, show_on_maa');
		$query->from('#__nbs_fees');
		$query->where('state=1');
		$query->where('nbs_id=' . $id);
		if (strtolower($for_user_group) == 'landlord')
			$query->where('show_on_maa>0');

		// Setup the query
		$db->setQuery($query);
		$fees = $db->loadObjectList();

		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		foreach ($fees as &$fee) {
			if(!$fee->value = @number_format($fee->value,2,'.',''))
				$fee->value = '0.00';
		}

		return $fees;
	}

	public function saveFees($fees, $nbs_id)
	{
		if (empty($fees)) {
			$this->setError('Emtpy data not allowed to save accounts');
			return false;
		}

		$user = JFactory::getUser();

		if (!$fees)
			return true;

		jimport('jentla.rest');
		$post = array (
			'fees'	=> $fees,
			'nbs_id'	=> $nbs_id,
			'rest_user' => $user->get('id'),
			'site_id'	=> Utilities::getSite('id')
		);

		$rest = JRest::call('manager', 'com_jentlacontent.nbsignup.saveFees/site', $post);
		if ($rest_error = $rest->getError()) {
			$this->setError($rest_error);
			return false;
		}

		if (!$response = $rest->getResponse(true)) {
			$this->setError('Empty response from property save action');
			return false;
		}

		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		if (!$actionfieldModel->update($response)) {
			$this->setError($actionfieldModel->getError());
			return false;
		}

		return $response;
	}

	public function sendIntroEmail($data = array())
	{
		$nbs_id = JArrayHelper::getValue($data, 'nbs_id');

		if(!$nbs_id) {
			if (!$nbs_id = JRequest::getVar('nbs_id')) {
				$this->setError('Empty nbs id not allowed for sendIntroEmail');
				return false;
			}
		}

		$nbs = $this->getItem($nbs_id);

		if ($error = $this->getError()) {
			$this->setError($error);
			return false;
		}

		if (!$nbs) {
			$this->setLogError(__METHOD__, 'Empty nbs data not allowed for sendIntroEmail', 'nbs_intro_emails', false);
			return false;
		}

		if (!$nbtype = $this->getBusinessType($nbs->business_type)) {
			if (!$error = $this->getError())
				$error = 'Unable to load business type on sendIntroEmail';
			$this->setLogError(__METHOD__, $error, 'nbs_intro_emails', false);
			return false;
		}

		$nbtype_params = $nbtype->params;
		$intro_mail_on_complete = JArrayHelper::getValue($nbtype_params, 'intro_mail');
		if ($intro_mail_on_complete) {
			if (!$property_owners = $nbs->property_owner) {
				$this->setLogError(__METHOD__, 'Property owner not found for sendIntroEmail for nbs_id: ', $nbs_id, 'nbs_intro_emails', false);
				return false;
			}

			// $landlord = $this->loadLandlords('', explode(',', $property_owner), true);
			$query  = 'SELECT ow.id, ow.email, ow.name, ow.landlord_mail_flag,ju.cc_email FROM #__owner as ow
			INNER JOIN #__jentlausers ju on ju.id = ow.id
			WHERE ow.id IN ('. $property_owners .');';
			if (!$landlords = Utilities::getSqlResult($query, true)) {
				if ($error = Utilities::getError()) {
					$this->setLogError(__METHOD__, 'Error in load landlord: ', $error, 'nbs_intro_emails', false);
					return false;
				}
				$this->setLogError(__METHOD__, 'Empty property owners not allowed for sendIntroEmail', 'nbs_intro_emails', false);
				return false;
			}

			$post_data = array (
				'landlords' => $landlords,
				'property_agent' => $nbs->property_agent,
				'property_manager' => $nbs->property_manager,
				'property_id' => $nbs->property_id
			);

			if (!Utilities::addJob($post_data, 'manager', 'com_jentlacontent.nbsignup.sendIntroEmail/site', 'NBS: Intro Email ' . __METHOD__ . $nbs->id)) {
				if (!$error = Utilities::getError())
					$error = 'Unable to send intro mail for NBS: ' . $nbs->id;
				$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'nbs_intro_emails', false);
				return false;
			}
		}
		return true;
	}

	public function downloadReport($data)
	{
		$user = JFactory::getUser();
		if (!$user_id = $user->get('id')) {
			$this->setError('Unable to load user id NBS report');
			return false;
		}

		if (!$ids = JArrayHelper::getValue($data, 'ids')) {
			$this->setError('Unable to load signup id');
			return false;
		}
		$ids = explode(',',$ids);
		jimport('jentla.rest');

		foreach ($ids as $id) {

			$report_type = JArrayHelper::getValue($data, 'report_type', 'main');
			$content = '';
			$outFileName = 'nbs_' . $report_type . '_' . $id . '.pdf';

			if (!$path = JArrayHelper::getValue($data, 'path'))
				$path = 'images/documents/nbs/export/' .$id;

			if (!$item = $this->getItem($id)) {
				$this->setError('Unable to load items for nbs: ' . $id);
				return false;
			}

			if ( $report_type == 'main' ) {
				$content = $this->prepareHTML(array(
					'id' => $id,
					'type_id' => $item->business_type,
					'tmpl' => 'raw'
				));
			} else {
				$content = $this->prepareHTML(array(
					'id' => $id,
					'type_id' => $item->business_type,
					'tmpl' => 'raw',
					'layout' => 'preview_additional'
				));
			}

			if (empty($content)) {
				$this->setError('Unable to prepare report content for nbs: ' . $id);
				return false;
			}

			// if(!$pdf = Utilities::createPdf($content, $outFileName, '', false, $path)) {
			// 	$this->setError(Utilities::getError());
			// 	return false;
			// }

			$post = array ( 'source' => $content, 'target' => $path. '/' .$outFileName);
			$rest = JRest::call('manager', 'com_jentlacontent.ourtradie.wkhtmltopdf/site', $post);

			if ($rest_error = $rest->getError()) {
				$this->setError($rest_error);
				return false;
			}

			if (!$response = $rest->getResponse(true)) {
				$this->setError('Empty response from property save action');
				return false;
			}
			echo "PDF path: " .$response ."<br>";
		}

		return true;
	}

	public function updatePaymentPropertyMap($data)
	{
		if (!$nbs_id = JArrayHelper::getValue($data, 'nbs_id')) {
			if (!$nbs_id = JRequest::getInt('nbs_id')) {
				$this->setLogError(__METHOD__, 'Empty nbs_id not allowed for update payment property map', 'nbs_update_payment_map', false);
				return false;
			}
		}

		if (!$nbs = $this->getItem($nbs_id)) {
			$this->setError('Unable to load Nbs details');
			return false;
		}

		$past_id = $nbs->past_id;
		$payment_system = $nbs->payment_system;

		if (intval($past_id) > -1 && strtoupper($payment_system) == 'OP') {
			if (!$property_id = $nbs->property_id) {
				$this->setLogError(__METHOD__, 'Unable to load property_id for payment property map', 'nbs_update_payment_map', false);
				return false;
			}

			if (!$property_agent = $nbs->property_agent) {
				$this->setLogError(__METHOD__, 'Unable to load property_id for payment property map', 'nbs_update_payment_map', false);
				return false;
			}

			if (!$agent = JentlacontentHelperOurTradie::getAgent($property_agent)) {
				$this->setLogError(__METHOD__, 'Unable to load your agency details for payment property map', 'nbs_update_payment_map', false);
				return false;
			}


			$qry = 'SELECT pm.* FROM #__payment_property_map AS pm WHERE property_id = '. $property_id .
				' AND pm.agency_id = '. $property_agent .
				' AND (livemode = 0 OR pending = 1) ORDER BY id LIMIT 1';
			$payment = Utilities::getSqlResult($qry, false);

			Utilities::addLogs('qry: ' .$qry, 'nbs_update_payment_map',true,'front');
			Utilities::addLogs('Payment Details: ' .print_r($payment, true), 'nbs_update_payment_map',true,'front');

			if ($error = Utilities::getError()) {
				$this->addLog(__METHOD__, ' Error on payment property map: ' . $error, 'nbs_update_payment_map', false);
				return false;
			}

			if ($payment && Utilities::getDate($agent->pp_live_date) && $agent->trusted_source == 'ourproperty') {
				$livemode = $agent->pp_live_date <= JHtml::date('now','Y-m-d') ? 1 : 0;

				$payment['live_date'] = $livemode ? JHtml::date('now','Y-m-d') : $agent->pp_live_date;
				$payment['livemode'] = $livemode;
				$payment['pending'] = $livemode ? 0 : 1;

				$property = array();
				$property['payment_system_pending'] = $livemode ? 0 : 1;
				$property['id'] = $property_id;

				$tables = array();
				$tables[] = array ('table' => 'payment_property_map', 'key' => 'id', 'data' => array($payment));
				$tables[] = array ('table' => 'property', 'key' => 'id', 'data' => array($property));

				Utilities::addLogs($tables, 'nbs_update_payment_map',true,'front');

				$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
				if (!$result = $actionfieldModel->saveTables($tables)) {
					$this->setError($actionfieldModel->getError());
					return false;
				}
			}
		}
		return true;
	}

	public function afterComplete($id)
	{
		$log_name = 'nbs_after_complete';

		if (!$id) {
			if (!$id = JRequest::getInt('nbs_id')) {
				$this->setLogError(__METHOD__, 'FAILED: ' . 'Empty id not allowed to afte complete NBSU', $log_name, false);
				return false;
			}
		}

		if (!$item = $this->getItem($id)) {
			$this->setLogError(__METHOD__, 'FAILED: ' . 'Unable to load items for nbs: ' . $id, $log_name, false);
			return false;
		}

		if (!$property_id = $item->property_id) {
			$this->setLogError(__METHOD__, 'Unable to find property reference on after complete nbs: ' . $item->id, $log_name, false);
			return false;
		}

		if (!$property = $this->getProperty(array ('property_id' => $property_id))) {
			if (!$this->getError())
				$this->setLogError(__METHOD__, 'Unable to find property on after complete nbs: ' . $item->id, $log_name, false);
			return false;
		}

		if ((int)$item->past_id == -1)
		{
			if ((int)$item->property_inactive == 1) {
				$this->markAsCurrent(array ('id' => $item->id, 'skip_validate' => true));
			}
			else {
				if($item->payment_system != 'OP' && $item->management_start <= JHtml::date('now', 'Y-m-d')) {
					$this->markAsCurrent(array ('id' => $item->id));
					// Skip error right now.
				}
			}
		}
		else
		{
			if ((int)$item->state > -1) {
				Utilities::addLogs('Current NBSU start', 'aftercomp');
				if ((int)$item->property_inactive == 1) {
					Utilities::addLogs('Inactive prop', 'aftercomp');
					//Activate the property
					$property_save = array();
					$update_roles = array();

					$property_save['id'] = $item->property_id;
					$property_save['inactive'] = 0;
					$property_save['property_expiry'] = '0000-00-00 00:00:00';

					$update_roles['property_id'] = $item->property_id;
					$update_roles['property_agent'] = $item->property_agent;

					$tables = array();
					$tables[] = array ('table' => 'property', 'key' => 'id', 'data' => array ($property_save));

					$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
					if (!$saved_tables = $actionfieldModel->saveTables($tables)) {
						if (!$error = $actionfieldModel->getError())
							$error = 'Unable to make property active while completeing NBSU for: ' .$item->id;
						$this->setLogError(__METHOD__, 'FAILED: ' . $error, $log_name, false);
						return false;
					}

					if (!$this->addPropertyRoles($update_roles))
						return false;

					if (!$this->updatePaymentPropertyMap(array('nbs_id' => $item->id)))
						return false;
				}

				if (!$this->saveDisbursementRules((array)$property))
					return false;

				if (!$this->updatePropertyFees($item->property_id))
					return false;

				if (!$this->saveSharedProperties($item->id))
					return false;
			}
		}

		if (!$this->sendIntroEmail(array('nbs_id' => $item->id)))
			return false;

		$landlords = array();
		$landlords['user_ids'] = $item->property_owner;
		if (!Utilities::addJob($landlords, 'manager', 'com_jentlacontent.ourtradie.activateUsers/site', 'NBS: Activate Landlords ' . __METHOD__ . $item->id)) {
			if (!$error = Utilities::getError())
				$error = 'Unable to activate landlords for the NBS : ' . $item->id;
			$this->setLogError(__METHOD__, 'FAILED: ' . $error, $log_name, false);
			return false;
		}

		return true;
	}

	public function prepareTenantNotice($data)
	{
		if (!$id = JArrayHelper::getValue($data, 'nbs_id')) {
			$this->addLog(__METHOD__, 'Empty nbs_id not allowed for prepare tenant notice', 'nbs_tenant_notice', false);
			return false;
		}

		if (!$nbs = $this->getItem($id)) {
			$this->addLog(__METHOD__, 'Unable to load Nbs details: ' . $id, 'nbs_tenant_notice', false);
			return false;
		}

		$property = Utilities::getTable('property');
		$property->load($nbs->property_id);

		$user = JFactory::getUser();
		if (!$user_id = $user->get('id'))
			$user_id = $property->property_manager;

		if (!$property->property_tenant) {
			$this->addLog(__METHOD__, 'Property: ' . $nbs->property_id . ' has no Tenant', 'nbs_tenant_notice', false);
			return true;
		}
		$property_tenants = explode(',', $property->property_tenant);

		$valid = $invalid = array();
		foreach ($property_tenants as $property_tenant) {
			if (JentlacontentHelperOurTradie::checkValidUserById($property_tenant, true))
				$valid[] = $property_tenant;
			else
				$invalid[] = $property_tenant;
		}

		$noticeData = array (
			'nbs_id' => $id, 
			'property_id' => $property->id, 
			'property_agent' => $property->property_agent, 
			'property_manager' => $property->property_manager,
			'rest_user' => $user_id
		);

		if (!empty($invalid)) {
			$noticeData['property_tenant'] = implode(',', $invalid);
			if (!$this->saveTenantNotice($noticeData)) {
				if (!$error = $this->getError())
					$error = 'Unable to prepare tenant notice for tenants: ' . $noticeData['property_tenant'];
				$this->addLog(__METHOD__, 'FAILED: ' . $error, 'nbs_tenant_notice', false);
			}
		}

		if (!empty($valid)) {
			$noticeData['property_tenant'] = implode(',', $valid);
			if (!Utilities::addJob($noticeData, 'node', 'com_jentlacontent.nbsignup.sendTenantNotice/site', 'NBS: Tenant notice-' . $property_id)) {
				if (!$error = Utilities::getError())
					$error = 'Unable to prepare tenant notice for tenants: ' . $noticeData['property_tenant'];
				$this->addLog(__METHOD__, 'FAILED: ' . $error, 'nbs_tenant_notice', false);
			}
		}

		return true;
	}

	public function saveTenantNotice($data)
	{
		if (!$user_id = JArrayHelper::getValue($data, 'rest_user')) {
			$this->setLogError(__METHOD__, 'Empty user_id not allowed for save tenant notice', 'nbs_tenant_notice', false);
			return false;
		}

		if (!$nbs_id = JArrayHelper::getValue($data, 'nbs_id')) {
			$this->setLogError(__METHOD__, 'Empty nbs_id not allowed for save tenant notice', 'nbs_tenant_notice', false);
			return false;
		}

		if (!$property_id = JArrayHelper::getValue($data, 'property_id')) {
			$this->setLogError(__METHOD__, 'Empty property_id not allowed for save tenant notice', 'nbs_tenant_notice', false);
			return false;
		}

		if (!$tenants = JArrayHelper::getValue($data, 'property_tenant')) {
			$this->setLogError(__METHOD__, 'Empty tenants not allowed for save tenant notice: ' . $nbs_id, 'nbs_tenant_notice', false);
			return false;
		}
		$property_tenants = is_array($tenants) ? $tenants : explode(',', $tenants);

		$agencyModel = JModel::getInstance('AgencyModel', '', array('ignore_request' => true));
		if (!$tenantNotice = $agencyModel->loadTemplate('change_of_lessor_report')) {
			$this->setLogError(__METHOD__, $agencyModel->getError(), 'nbs_tenant_notice', false);
			return false;
		}

		jimport('jentla.rest');
		$docsData = array();
		foreach ($property_tenants as $property_tenant) {
			$append = array();
			$tenant = $agencyModel->loadUser($property_tenant);
			$append['toname'] = !empty($tenant['name']) ? $tenant['name'] : '';
			$append['tosalutation'] = !empty($tenant['salutation']) ? $tenant['salutation'] : $append['toname'];
			if (!$report = $agencyModel->getTemplateWithContentType($nbs_id, 'nbsignup', $tenantNotice->name, $append)) {
				$this->setLogError(__METHOD__, $agencyModel->getError(), 'nbs_tenant_notice', false);
				return false;
			}

			if (empty($report->content)) {
				$this->setLogError(__METHOD__, 'Unable to prepare report content for tenant: ' . $tenant, 'nbs_tenant_notice', false);
				return false;
			}

			$path = 'images/documents/tenantNotices' . DS . $user_id . DS . $property_tenant . DS . Utilities::getServerDate('Y-m-d');
			$name = 'Change_of_Lessor_Notice_' . $property_tenant . '_' . JHtml::date('now', 'Y-m-d') . '.pdf';
			$docsData[] = array (
				'list_type' => 'nbs',
				'list_id' => $nbs_id,
				'property_id' => $property_id,
				'category' => '126',
				'type' => 'P',
				'title' => 'Change of lessor notice',
				'path' => $path . DS . 'pdf' . DS . $name,
				'map_user_id' => $property_tenant,
				'agency_id' => JArrayHelper::getValue($data, 'property_agent'),
				'document' => array (
					'target' => $path . DS . $name,
					'source' => $report->content
				),
				'origin' => 'N'
			);
		}

		if (!$this->saveDocuments($docsData)) {
			if (!$error = $this->getError())
				$error = 'Unable to save tenant notice for tenants: ' . $tenants;
			$this->addLog(__METHOD__, $error, 'nbs_tenant_notice', false);
		}

		return true;
	}

	public function sendTenantNotice($data)
	{
		if (!$user_id = JArrayHelper::getValue($data, 'rest_user')) {
			$this->setLogError(__METHOD__, 'Empty user_id not allowed for send tenant notice', 'nbs_tenant_notice', false);
			return false;
		}
		Utilities::bindLogger($user_id);

		if (!$nbs_id = JArrayHelper::getValue($data, 'nbs_id')) {
			$this->setLogError(__METHOD__, 'Empty nbs_id not allowed for send tenant notice', 'nbs_tenant_notice', false);
			return false;
		}

		if (!$property_tenant = JArrayHelper::getValue($data, 'property_tenant')) {
			$this->setLogError(__METHOD__, 'Empty property_tenant not allowed for send tenant notice: ' . $nbs_id, 'nbs_tenant_notice', false);
			return false;
		}

		if (!$property_manager = JArrayHelper::getValue($data, 'property_manager')) {
			$this->setLogError(__METHOD__, 'Empty property_manager not allowed for send tenant notice: ' . $nbs_id, 'nbs_tenant_notice', false);
			return false;
		}

		$action = array(
			'force_send' => true
		);
		$agencyModel = JModel::getInstance('AgencyModel', '', array('ignore_request' => true));
		if (!$sent = $agencyModel->sendMailWithContentType($nbs_id, 'nbsignup', $property_tenant, 'change_of_lessor_notice', $action, $property_manager, array(), null,'responsive_agency_cover')) {
			if (!$error = $agencyModel->getError())
				$error = 'Unable to send tenant notice for tenants: ' . $property_tenant;
			$this->addLog(__METHOD__, 'FAILED: ' . $error, 'nbs_tenant_notice', false);
		}

		return true;
	}

	public function markAsPastByProperty($data)
	{
		$user = JFactory::getUser();
		if ($post_in_data = JArrayHelper::getValue($data, 'data'))
			$data = $post_in_data;

		if (empty($data))
			$this->addLog(__METHOD__, ' Empty data not allowed to mark as past', 'nbs_past', false);

		if (!$property_id = JArrayHelper::getValue($data, 'property_id'))
			$this->addLog(__METHOD__, ' Empty property_id not allowed to mark as past', 'nbs_past', false);

		$this->addLog(__METHOD__, ' Mark as Past for property: ' . $property_id . ' actioned by: ' .$user->id, 'nbs_past', false);
		$current_item = $this->getCurrentItem($property_id);
		if ($current_item === false) {
			if (!$error = $this->getError())
				$error = 'Unable to load current item of property_id: ' . $property_id;
			$this->addLog(__METHOD__, $error, 'nbs_past', false);
		}

		if (empty($current_item))
			return true;

		$past_data = (object)array();
		$past_data = $current_item;
		$date = JFactory::getDate();
		$past_data->management_end = $date->toSql();
		$past_data->state = NBS_PAST;
		if ($future_id = JArrayHelper::getValue($data, 'future_id'))
			$past_data->future_id = $future_id;

		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		if (!$actionfieldModel->saveItem($past_data, 'nbs')) {
			$this->addLog(__METHOD__, $actionfieldModel->getError(), 'nbs_past_error', false);
			return false;
		}

		return true;
	}

	public function getPropertyFees($property_id)
	{
		if(!$property_id) {
			$this->setError('Empty property id is not allowed to load Property Fees');
			return false;
		}

		$db		= $this->getDbo();
		$query	= $db->getQuery(true);
		$query->select('af.maa_field AS name, pf.amount AS value, pf.units AS unit, pf.property_id');
		$query->from('#__property_fees AS pf');
		$query->join('INNER', '#__agency_fees AS af ON af.id = pf.agency_fee_id AND af.status = 1');

		// Setup the query
		$db->setQuery($query);
		if (is_array($property_id))
			$property_id = implode(',', $property_id);

		$query->where('property_id IN (' . $property_id . ')');
		$fees = $db->loadObjectList();
		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}

		foreach ($fees as &$fee) {
			if ($fee->name == 'let_fee_amt')
				$fee->name = 'let_fee';

			switch ($fee->unit) {
				case 'A':
					$fee->unit = '% of Annual Rent';
					break;
				case '%W':
					$fee->unit = '% of Weekly Rent';
					break;
				case '%':
					$fee->unit = '%';
					break;
				case 'W':
					$fee->unit = 'Weeks Rent';
					break;
				case 'AC':
					$fee->unit = 'At Cost';
					break;
				case 'PH':
					$fee->unit = 'Per Hour';
					break;
				case 'PI':
					$fee->unit = 'Per Item';
					break;
				default:
					$fee->unit = '$';
					break;
			}
		}

		return $fees;
	}

	public function import($data)
	{
		if (!$agent = JentlacontentHelperOurTradie::getAgent()) {
			$this->setLogError(__METHOD__, 'Unable to load your agency details when import NBSU', 'import_nbsu', false);
			return false;
		}

		if (!$property_ids = JArrayHelper::getValue($data, 'property_ids')) {
			if (!$property_ids = JRequest::getVar('property_ids')) {
				$this->setLogError(__METHOD__, 'Empty property_id not allowed to import NBSU', 'import_nbsu', false);
				return false;
			}
		}
		$property_ids = !is_array($property_ids) ? explode(',', $property_ids) : $property_ids;

		$file = JArrayHelper::getValue($data, 'file');
		foreach ($property_ids as $property_id) {
			$save_data = array (
				'property_id' => $property_id,
				'file' => $file
			);
			if (!Utilities::addJob($save_data, 'node', 'com_jentlacontent.nbsignup.importNbsu/site', 'Save Imported NBSU ' . $property_id)) {
				if (!$error = Utilities::getError())
					$error = 'Unable to save NBSU' . $property_id;
				$this->setLogError(__METHOD__, $error, 'import_nbsu', false);
				return false;
			}
		}

		Utilities::addTextLogs('Imported Successfully.', 'import_nbsu');
		return true;
	}

	public function importNbsu($data)
	{
		if (empty($data)) {
			$this->setLogError(__METHOD__, 'Empty data is not allowed to save nbsu', 'import_nbsu', false);
			return false;
		}

		if (!$property_id = JArrayHelper::getValue($data, 'property_id')) {
			if (!$property_id = JRequest::getVar('property_id')) {
				$this->setLogError(__METHOD__, 'Empty property_id not allowed to save NBSU', 'import_nbsu', false);
				return false;
			}
		}
		if (!$property = (array)$this->getProperty(array('property_id' => $property_id))) {
			if (!$error = $this->getError())
				$error = 'Unable to load property when save NBSU';
			$this->setLogError(__METHOD__, $error, 'import_nbsu', false);
			return false;
		}
		Utilities::bindLogger($property['property_agent']);

		if (!$property_owner = JArrayHelper::getValue($property, 'property_owner')) {
			if (!$property_owner = JArrayHelper::getValue($data, 'property_owner')) {
				$this->setLogError(__METHOD__, 'Empty property_owner not allowed to save NBSU', false);
				return true;
			}
		}

		if (!$owner_details = $this->getLandlords($property_owner)) {
			if (!$error = $this->getError())
				$error = 'Unable to load property owner when save NBSU';
			$this->setLogError(__METHOD__, $error, 'import_nbsu', false);
			return false;
		}

		$property_fees = $this->getPropertyFees($property_id);
		if ($error = $this->getError()) {
			$this->setLogError(__METHOD__, $error, 'import_nbsu', false);
			return false;
		}

		if (!$file = JArrayHelper::getValue($data, 'file'))
			$file = JRequest::getVar('file');

		$save_data = array (
			'property_id'		=> $property_id,
			'property'			=> $property,
			'property_owner'	=> $property['property_owner'],
			'past_id'			=> 0,
			'ownership'			=> $property['ownership'],
			'fees'				=> $property_fees
		);
		$save_data['property']['landlords'] = $owner_details;
		if (!empty($file)) {
			$save_data['initial_signed'] = JFactory::getDate()->toSql();
			$save_data['manually_signed'] = JFactory::getDate()->toSql();
			$save_data['completed_origin'] = 'Upload';
			$save_data['state'] = 3;
		}
		if (!$nbs_id = $this->save($save_data)) {
			if (!$error = $this->getError())
				$error = 'Unable to save NBSU' . $property_id;
			$this->setLogError(__METHOD__, $error, 'import_nbsu', false);
			return false;
		}

		if ($property_owner = JArrayHelper::getValue($data, 'property_owner')) {
			$access_data = array (
				'property' => $property_id,
				'agency_id' => $property['property_agent'],
				'change_role' => 1,
				'restricted_roles' => array ('app_roles' => 0, 'viewing_roles' => 0)
			);
			if (!Utilities::addJob($access_data, 'manager', 'com_jentlacontent.import.UpdatePropertyRoles/site', 'Added access for imported property ' . $property_id)) {
				if (!$error = Utilities::getError())
					$error = 'Unable to update access for property' . $property_id;
				$this->setLogError(__METHOD__, 'FAILED: ' . $error, 'import_nbsu', false);
				return false;
			}
		}

		if (!empty($file)) {
			$complete_data	= array (
				'path'				=> $file,
				'list_id'			=> $nbs_id,
				'content_subtype'	=> 'main',
				'content_type'		=> 'nbs',
				'property_id'		=> $property_id,
				'property_owner'	=> $property['property_owner'],
				'property_agent'	=> $property['property_agent'],
				'user_id'			=> $property['property_agent']
			);
			if (!Utilities::addJob($complete_data, 'manager', 'com_jentlacontent.nbsignup.saveDocForImportedNbsu/site', 'Imported NBSU Complete ' . $nbs_id)) {
				if (!$error = Utilities::getError())
					$error = 'Unable to complete NBSU' . $nbs_id;
				$this->setLogError(__METHOD__, $error, 'import_nbsu', false);
				return false;
			}
		}

		Utilities::addTextLogs('Imported Successfully- Property ID: ' . $property_id . ' NBSU ID: ' . $nbs_id, 'import_nbsu');
		return true;
	}

	public function mortonProperty()
	{
		return $this->import(array(
			'property_ids' => array(1966665,1966666,1966667,1966668,1966669,1966670,1966671,1966672,1966673,1966674,1966675,1966676,1966677,1966678,1966679,1966680,1966681,1966682),
			'file' => 'images/signups/19585/2024-07-01/nbs_19585_2024-07-01_000000.pdf'
		));
	}

	public function afterSubmitNBS($property_id = 0)
	{
		if (empty($property_id)) {
			$this->setError('Empty property id not allowed');
			return false;
		}

		$post_data = array( 'property_id' => $property_id );
		jimport('jentla.rest');
		$rest = JRest::call('manager', 'com_jentlacontent.payinvoices.deleteMortgageInvoice/site', $post_data);
		if ($rest->getError()) {
			$this->setLogError(__METHOD__, 'FAILED: ' . 'Delete Mortgage invoice failed: ' . $property_id, 'delete_mortgage_invoice', false);
			return false;
		}

		return true;
	}

	public function markNbsAsPast($data)
	{
		if ($post_in_data = JArrayHelper::getValue($data, 'data'))
			$data = $post_in_data;

		if (!$nbs_id = JArrayHelper::getValue($data, 'nbs_id')) {
			$this->setError('Empty nbs id not allowed to mark as past');
			return false;
		}

		$save_data = array (
			'id' => $nbs_id,
			'state' => NBS_PAST
		);

		$table_data = array (
			'key' => 'id',
			'table' => 'nbs',
			'data' => array ($save_data)
		);
		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		if (!$actionfieldModel->saveTable($table_data)) {
			if (!$error = $actionfieldModel->getError())
				$error = 'Unable to mark old NBSU as past: ' . $nbs_id;
			$this->setError($error);
			return false;
		}

		return true;
	}
	public function sendRenewalCompletionMail($data)                              
	{
		Utilities::addLogs(print_r($data, true), 'sendRenewalCompletionMail',true);
		if (!$id = JArrayHelper::getValue($data, 'id')) {
			$this->setError('Unable to load signup id');
			return false;
		}
		if (!$landlordId = JArrayHelper::getValue($data,'property_owner')){
			$this->setError('Unable to load landlord id');
			return false;
		}
		$ll_mail_tpl = 'll_renewal_completed_email';
			$agencyModel = JModel::getInstance('AgencyModel', '', array('ignore_request' => true));
			if (!$sent = $agencyModel->sendMailWithContentType($id, 'nbsignup', $landlordId, $ll_mail_tpl, '', '',array(),'', 'responsive_agency_cover')) {
				$this->setError($agencyModel->getError());
				return false;
			}
		return true;
	}
	public function validateIdDocuments($landlordId, $nbs_id,$isValid = false)
	{
		if (empty($landlordId) || empty($nbs_id)) {
			return 'Please provide a valid Id.';
		}

		$landlordId = is_array($landlordId) ? array_unique(array_filter($landlordId)) : array($landlordId);
		$select = 'jentlausers.id, CONCAT_WS(" ", jentlausers.name, jentlausers.lastname) AS fullname, jentlausers.email';
		$users = JentlacontentHelperOurTradie::loadUsersById($landlordId, $select);

		if (empty($users)) {
			return 'Please provide a valid user';
		}

		$checkIdDocuments = $this->fetchIDDocuments(array('nbs_id' => $nbs_id, 'landlord_id' => $landlordId));
		if (empty($checkIdDocuments)) {
			return "Please provide Proof of Identity document.<br>";
		}
		$checkIdDocuments = Utilities::arrayPivot($checkIdDocuments, 'user_id');
		foreach($checkIdDocuments as &$checkIdDocument) {
			$checkIdDocument = Utilities::arrayPivot($checkIdDocument, 'category');
		}

		$documentsCategories = array('Primary', 'Secondary', 'Ownership');
		$error_msg = "";

		foreach ($users as $key => $user) {
			$msg = "<b>{$user->fullname} ({$user->email})</b><br>";
			foreach ($documentsCategories as $category) {
				$userDocuments = JArrayHelper::getValue($checkIdDocuments, $key, array());
				$documents = JArrayHelper::getValue($userDocuments, $category, array());
				if ($category == 'Primary' && count($documents) < 1) {
					$error_msg .= $msg . "Please provide 1 Primary Proof of Identity document.<br>";
				} elseif ($category == 'Secondary' && count($documents) < 2) {
					$error_msg .= $msg . "Please provide 2 Secondary Proof of Identity documents.<br>";
				} elseif ($category == 'Ownership' && count($documents) < 1) {
					$error_msg .= $msg . "Please provide 1 Proof of Legal Ownership of Property document.<br>";
				}

				if (!empty($error_msg)) {
					return $error_msg;
				}
				foreach ($documents as $doc) {
					if ($isValid) {
						if (JArrayHelper::getValue($doc, "verified_status") != 1) {
							return $msg . 'Please Verify all documents<br>';
						}
					} else {
						if (JArrayHelper::getValue($doc, "verified_status") == -1) {
							return $msg . 'Rejected Documents – Reupload Required<br>';
						}
					}
				}
			}
		}
		return $error_msg;
	}

	public function saveidCheckDocuments($data = array())
	{
		if (!$documents = JArrayHelper::getValue($data, 'document_details')) {
			$this->setError('Upload Documents');
			return false;
		}
		if (!$nbs_id = JArrayHelper::getValue($data, 'nbs_id')) {
			$this->setError('Unable to load NBS documents');
			return false;
		}
		$keys = array("document_no", "issued_place", "issued_date", "expiry_date");
		$extra = array_intersect_key($data, array_flip($keys));
		if (!empty($extra)) {
			$documents['extra'] = $extra;
			$data['document_info'] = $extra;
		}
		if (JArrayHelper::getValue($data, 'document_id')){
			if(!$this->deleteIdDocuments($data)){
				return false;
			}
		}

		$documents['map_user_id'] = $data['llId'];
		if (!$uploaddoc = $this->saveDocuments(array($documents))) {
			if (!$this->getError())
				$this->setError('Unable to save documents.');
			return false;
		}

		$uploaddoc =  $uploaddoc[0]['data'][0];
		$data['document_id'] = JArrayHelper::getValue($uploaddoc, 'id');
		if (empty($data['document_id'])) {
			$this->setError('Missing Documents Id');
			return false;
		}

		$data['type'] = $data['title'];
		$data['verified_status'] = $data['0'];
		$tables[] = array ('table' => 'nbs_id_check_documents', 'key' => 'id', 'data' => array ($data));

		if (JArrayHelper::getValue($data, 'check_id_document')) {
			$tables[] = array ('table' => 'nbs', 'key' => 'id', 'data' => array (array('check_id_document'=>1 ,'id'=>$nbs_id)));
		}
		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		if (!$actionfieldModel->saveTables($tables)) {
			$this->setError($actionfieldModel->getError());
			return false;
		}
	}
	public function deleteIdDocuments($data)
	{
		if (!$document_id = JArrayHelper::getValue($data, 'document_id')) {
			$this->setError('Upload Documents');
			return false;
		}
		$documents = array('id'=>$document_id,'state'=>0);
		$tables = array ('table' => 'documents', 'key' => 'id', 'data' => array ($documents));
		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		if (!$actionfieldModel->saveTable($tables)) {
			$this->setError($actionfieldModel->getError());
			return false;
		}
		return true;
	}
	public function verifyDocument($data)
	{
		if (!$id = JArrayHelper::getValue($data, 'id')) {
			$this->setError('Upload Documents');
			return false;
		}
		if (!$value = JArrayHelper::getValue($data, 'value')) {
			$this->setError('Upload Documents');
			return false;
		}
		$user = JFactory::getUser();

		$documents = array('id'=>$id,'verified_status'=>$value,'verified_by'=>$user->id,'verified_date'=>JFactory::getDate()->toSql());
		$tables[] = array ('table' => 'nbs_id_check_documents', 'key' => 'id', 'data' => array ($documents));
		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		if (!$actionfieldModel->saveTables($tables)) {
			$this->setError($actionfieldModel->getError());
			return false;
		}
		if (JArrayHelper::getValue($data, 'nbs_id') && JArrayHelper::getValue($data, 'landlord_id'))
			return $this->getIdDocuments($data);
		return true;
	}
	public function sendReviewMail($data)                              
	{
		if (!$id = JArrayHelper::getValue($data, 'id')) {
			$this->setError('Unable to load signup id');
			return false;
		}
		if (!$item = $this->getItem($id))
			return false;
		if (!$nbsManagerId = $item->signup_manager) {
			$this->setError('Unable to load from user');
			return false;
		}
		$pm_mail_tpl = 'pm_review_email';	
		$agencyModel = JModel::getInstance('AgencyModel', '', array('ignore_request' => true));
		if (!$sent = $agencyModel->sendMailWithContentType($id, 'nbsignup', $nbsManagerId, $pm_mail_tpl, '', '',array(),'', 'responsive_agency_cover')) {
			$this->setError($agencyModel->getError());
			return false;
		}
		return true;
	}
	public function sendRejectionMail($data){
		if (!$id = JArrayHelper::getValue($data,'id')) {
			$this->setError('Unable to load signup id');
			return false;
		}
		if(!$llid = JArrayHelper::getValue($data,'property_owner')){
			$this->setError('Unable to load Landlord Id');
			return false;
		}
		if(!$business_type = JArrayHelper::getValue($data,'business_type')){
			$this->setError('Unable to load business type');
			return false;
		}
		$uniqueLlid = array_values(array_unique($llid));   // To remove duplicate LL
		
		$documents = array('id'=>$id,'state'=>-4);		   //setting NBS state to -4
		$tables = array ('table' => 'nbs', 'key' => 'id', 'data' => array ($documents));
		$actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		if (!$actionfieldModel->saveTable($tables)) {
			$this->setError($actionfieldModel->getError());
			return false;
		}

		$data = array(
			'nbs_id' => $id,
			'landlord_id' => $uniqueLlid,
			'where' => 'cd.verified_status = -1'
		);
		if(!$redocs = $this->getIdDocuments($data)){	//To retrive IDDocs of all LL's
			return false;
		}
		if (empty($redocs['Primary']) && empty($redocs['Secondary']) && empty($redocs['Ownership'])) {
			return true; 
		}

		$ll_reject_mail_tpl = 'll_rejection_mail';
		$agencyModel = JModel::getInstance('AgencyModel', '', array('ignore_request' => true));
		foreach ($uniqueLlid as $landlordId) {			//List creation based on landlordwise 
			$tableContent = '';
			if (!empty($redocs)) {
				foreach ($redocs as $category => $docs) {
					$filteredDocs = array_filter($docs, function($doc) use ($landlordId) {
						return $doc['user_id'] == $landlordId;  
					});
					if (!empty($filteredDocs)) {
						$tableContent .= '<p style="font-weight: bold; margin-top: 15px; color:#333;">' . htmlspecialchars($category) . ':</p>';
						foreach ($filteredDocs as $doc) {
							$tableContent .= '<p>' . htmlspecialchars($doc['title']) . '</p>';
						}
					}
				}
			} else {
				$tableContent = '<p style="font-size: 15px; text-align: left; color: #595959;">No documents available.</p>';
			}
			$nbsuMenu = array (
				'site_alias' => 'landlord',
				'menu_alias' => 'landlord-nb-sign-up'
			);
			$nbsuItemId = JentlacontentHelperOurTradie::getSiteMenuItemid($nbsuMenu);
			$preview_link = 'index.php?option=com_jentlacontent&view=nbsignup&layout=landlord';
			$preview_link .= '&Itemid=' . $nbsuItemId;
			$nbs_ll_link = $preview_link. '&id=' . $id . '&type_id=' . $business_type. '&step=landlord_id';

			$DocTable['reuploadlink'] = '(%%landlord%%)/##AUTO_LINK('.$nbs_ll_link.')##';
			$DocTable['rejectedDocs'] = $tableContent;
			if (!$sent = $agencyModel->sendMailWithContentType($id, 'nbsignup', $landlordId, $ll_reject_mail_tpl, '', array(), $DocTable, '' ,'responsive_agency_cover')) {
				$this->setError($agencyModel->getError());
				return false;
			}	
		}
		
		return true;
	}
	public function sendResubmissionMail($data)                              
	{
		if (!$id = JArrayHelper::getValue($data, 'id')) {
			$this->setError('Unable to load signup id');
			return false;
		}
		if (!$landlordId = JArrayHelper::getValue($data,'property_owner')){
			$this->setError('Unable to load landlord id');
			return false;
		}
		$ll_mail_tpl = 'll_resubmission_email';
			$agencyModel = JModel::getInstance('AgencyModel', '', array('ignore_request' => true));
			if (!$sent = $agencyModel->sendMailWithContentType($id, 'nbsignup', $landlordId, $ll_mail_tpl, '', '',array(),'', 'responsive_agency_cover')) {
				$this->setError($agencyModel->getError());
				return false;
			}
		return true;
	}
	public function getIdDocuments($data = array())
	{
		if (!$items = $this->fetchIDDocuments($data)) {
			if($this->getError())
				return false;
		}
		$predefinedItems = array(
			'Primary' =>array(),
			'Secondary' =>array(),
			'Ownership' =>array()
		);
		$documentslabels = array(
			"Primary" => array(
				array(
					"optionsText" => "A current Australian driver’s licence or a current photo card issued by a State or Territory Government agency",
					"label" => "Australia Driver Licence",
					"value" => "australia_driver_licence"
				),
				array(
					"optionsText" => "A current Australian passport",
					"label" => "Australia Passport",
					"value" => "au_passport"
				),
				array(
					"optionsText" => "A current non-Australian passport",
					"label" => "Non Australia Passport",
					"value" => "non_au_passport"
				)
			),
			"Secondary" => array(
				array(
					"optionsText" => "A current Medicare card",
					"label" => "Medicare Card",
					"value" => "medicare_card"
				),
				array(
					"optionsText" => "A current credit card",
					"label" => "Credit Card",
					"value" => "credit_card"
				),
				array(
					"optionsText" => "A current passbook or an account statement from a bank, building society or credit union up to one year old",
					"label" => "Current Passbook / Account Statement",
					"value" => "account_statement"
				),
				array(
					"optionsText" => "A water rates notice up to one year old",
					"label" => "Water Rates Notice",
					"value" => "water_rates_notice"
				),
				array(
					"optionsText" => "A gas, electricity or council rates bill up to one year old",
					"label" => "Utility Bill",
					"value" => "utility_bill"
				),
				array(
					"optionsText" => "An electoral enrolment card or evidence of enrolment not more than two years old",
					"label" => "Electoral Enrolment",
					"value" => "electoral_enrolment"
				)
			),
			"Ownership" => array(
				array(
					"optionsText" => "The certificate of title for the property",
					"label" => "Property Title",
					"value" => "property_title"
				),
				array(
					"optionsText" => "A current council rates notice up to one year old",
					"label" => "Council Rates Notice",
					"value" => "council_rates_notice"
				),
				array(
					"optionsText" => "A land valuation notice up to one year old",
					"label" => "Land Valuation Notice",
					"value" => "land_valuation_notice"
				),
				array(
					"optionsText" => "A National Vendor Declaration concerning the relevant livestock",
					"label" => "National Vendor Declaration",
					"value" => "national_vendor_declaration"
				)
			)
		);
		foreach($items as $item){
			if(!empty($item['title'])){
				$documents = $documentslabels[$item['category']];
				foreach($documents as $documentsList){
					if($documentsList['value'] == $item['title']){
						$item['title_value']  = $item['title'];
						$item['title'] = $documentsList['label'];
					}
				}
			}
			if(!empty($item['document_info'])){
				$documentInfoArray = json_decode($item['document_info'], true);
				$item = array_merge($item, $documentInfoArray);
			}
			$predefinedItems[$item['category']][] = $item;
		}
		$predefinedItems['documentsList']=$documentslabels;
		return $predefinedItems;
	}
	public function fetchIDDocuments($data)
	{
		if (!$nbs_id = JArrayHelper::getValue($data, 'nbs_id')) {
			$this->setError('Unable to load NBS documents');
			return false;
		}
		if (!$landlord_id = JArrayHelper::getValue($data, 'landlord_id')) {
			$this->setError('Unable to load Landlord Id');
			return false;
		}

		if (is_array($landlord_id))
			$landlord_id = implode(',', $landlord_id);

		$db = $this->_db;
		$query	= $db->getQuery(true);
		$query->select('cd.id,d.id as document_id,dm.id as docmapid,d.title,d.path,d.state,dm.user_id,cd.category,cd.document_info,cd.verified_status,cd.verified_by,cd.verified_date,vj.fullname as verified_by_name');
		$query->from('#__documents AS d');
		$query->join('INNER', '#__documents_map dm on dm.document_id = d.id AND dm.user_id IN ('.$landlord_id.')');
		$query->join('INNER', '#__nbs_id_check_documents cd on cd.document_id = d.id AND cd.nbs_id = '.$nbs_id);
		$query->join('LEFT', '#__jentlausers vj on vj.id = cd.verified_by ');
		$query->where('d.state = 1');

		if ($where = JArrayHelper::getValue($data, 'where')) {
			$query->where($where);
		}
		$db->setQuery((string)$query);
		$items = $db->loadAssocList();

		if ($db->getErrorNum()) {
			$this->setError($db->getErrorMsg());
			return false;
		}
		return $items;
	}
	public function completedandsendReport($data = array())
	{
		if (!$nbs_id = JArrayHelper::getValue($data, 'id')) {
			$this->setError('Unable to load NBS documents');
			return false;
		}
		$data = $this->getDetailItem($nbs_id);
		$data = json_decode(json_encode($data), true);
		$state = NBS_COMPLETED;
		if (!$this->validateItem($data, false)) {
			$state = NBS_INCOMPLETE;
		}
		$data['state'] = $state;
		if (!$this->save($data))
			return false;

		if (!$agent = JentlacontentHelperOurTradie::getAgent()) {
			$this->setError('Unable to load your agency details');
			return false;
		}
		if (!$agent_id = (int)$agent->get('id')) {
			$this->setError('Unable to find your agency reference');
			return false;
		}

		if (!Utilities::addJob(array('id' => $nbs_id,'agent_id' => $agent_id), 'node', 'com_jentlacontent.nbsignup.generatePDFsandSendReport/site', 'generate PDFs and SendReport:- ' . $nbs_id)) {
				$this->setError('Unable to addjob for item - ' . $nbs_id);
				return false;
		}
		return true;
	}
	public function generatePDFsandSendReport($data)
	{
		if (!$nbs_id = JArrayHelper::getValue($data, 'id')) {
			$this->setError('Unable to load NBS documents');
			return false;
		}
		$user = JFactory::getUser();
		if (!$user->id) {
			if (!$agent_id = JArrayHelper::getValue($data, 'agent_id')) {
				$this->setError('Unable to load Agent ID');
				return false;
			}
			Utilities::bindLogger($agent_id);
			$user = JFactory::getUser();
		}

		$data = $this->getDetailItem($nbs_id);
		$data = json_decode(json_encode($data), true);
		$pdfPath = array();
		if (jarrayhelper::getvalue($data,'nb_check_id_documents') == 1) {
			$property = jarrayhelper::getvalue($data,'property');
			$landlords = jarrayhelper::getvalue($property,'landlords');
			foreach($landlords as $landlord){
				$llid =jarrayhelper::getvalue($landlord,'id');
				if ($previewDocuments = $this->previewDocumentPDF(array('id' => $nbs_id,'llid' => $llid)))
						$pdfPath[$llid] = $previewDocuments;
			}
		}

		$jrest = new JRest();
		$rest = $jrest->call('manager', 'com_jentlacontent.inspection.copyMedia/site', array('media_files'=>$pdfPath, 'site_id'=>2));
		if ($rest->getError()) {
			self::setError($rest->getError());
			return false;
		}
		$data['attachement'] = $pdfPath;
		if (!$this->sendReport($data)) {
			if (!$this->getError())
				$this->setError('Error: Send NBS report');
			return false;
		}
		return true;
	}
	public function downloadIdDocumentPDF($data = array())
	{
		if (!$id = JArrayHelper::getValue($data, 'id')) {
			$this->setError('Unable to load NBS ID 1');
			return false;
		}
		if (!$llid = JArrayHelper::getValue($data, 'llid')) {
			$this->setError('Unable to load NBS Landlord ID');
			return false;
		}
		if (!$pdf = $this->previewDocumentPDF($data, true)) {
			if (!$this->getError())
				$this->setError('Unable to load NBS ID 2');
			return false;
		}
		header('Content-Type: application/pdf');
		header('Cache-Control: public, must-revalidate, max-age=0');
		header('Pragma: public');
		header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
		header('Content-Description: File Transfer');
		header('Content-Length: '.strlen($pdf));
		header('Content-Disposition: inline; filename="IdentityChecklist.pdf"');
		echo $pdf;
		ob_flush();
		flush();
		exit;
	}
	public function previewDocumentPDF($data = array(), $isPreview = false)
	{
		if (!$id = JArrayHelper::getValue($data, 'id')) {
			$this->setError('Unable to load NBS ID 3');
			return false;
		}
		if (!$llid = JArrayHelper::getValue($data, 'llid')) {
			$this->setError('Unable to load NBS Landlord ID');
			return false;
		}
		$user = JFactory::getUser();
		$user_id = $user->get('id');
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, "http://node-service.our.property:4000/generate-pdf");
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
		curl_setopt($ch, CURLOPT_PROXY, '');
		$uri = JURI::getInstance();
		$host = $uri->getHost();

		if (strpos($host, '192.168.3.27') > -1) {
			curl_setopt($ch, CURLOPT_URL, "http://192.168.3.55:3000/generate-pdf");
		}
		if (strpos($host, 'ourtradiest') > -1 || strpos($host, 'ourtradieuat') > -1) {
			curl_setopt($ch, CURLOPT_URL, "http://st-node-service.our.property:4000/generate-pdf");
		}

		$salt = 'S4R!M1~VW2#@-2';
		$token = md5($user_id . $llid . $salt);
		$site_url = Utilities::getSite('public_url');
		$pdfPath = "/index.php?option=com_jentlacontent&view=idchecklist&tmpl=component&id={$id}&llid={$llid}&userID={$user_id}&token={$token}";
		$file = $site_url.$pdfPath;

		$data = json_encode(array('url' => $file,'waitForSelector'=>'#categoryDocuments'));

		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

		$response = curl_exec($ch);
		if ($response === false) {
			$error = curl_error($ch);
			curl_close($ch);
			$this->setError($error);
			return false;
		}
		if ($isPreview)
			return $response;

		$path = 'images/documents/nbs/export/pdf/';
		$site_path = JPATH_SITE . DS . $path;

		if (!JFolder::exists($site_path))
			JFolder::create($site_path);

		$outFileName = $llid . '_' . $id . '_idcheckDocuments.pdf';
		$pdfpath = $path . $outFileName;
		if (!JFile::write($site_path . $outFileName, $response))
		{
			$this->setError(JText::_('COM_CONFIG_ERROR_WRITE_FAILED'));
			return false;
		}

		return $pdfpath;
	}
	public function transferIdDocuments($data = array())
	{
		$oldNbsId = JArrayHelper::getValue($data, 'oldNbsId');
		$newNbsId = JArrayHelper::getValue($data, 'newNbsId');

		Utilities::addLogs(print_r($data, true), 'transferIdDocuments25',true);
		if (empty($oldNbsId) || empty($newNbsId)) {
			$this->setError('Unable to load NBS ID 4');
			return false;
		}

		// $update = array('id' => $newNbsId, 'check_id_document' => 1);
		// $tables = array('table' => 'nbs', 'key' => 'id', 'data' => array($update));
		// Utilities::addLogs(print_r($update,true),'tupdate',true);
		// $actionfieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		// if (!$actionfieldModel->saveTable($tables)) {
		// 	Utilities::addLogs(print_r('Surprise mf',true),'savefail',true);
		// 	$this->setError($actionfieldModel->getError());
		// 	return false;
		// }

		$db = $this->_db;

		// Fetch documents from old NBS ID
		$query = $db->getQuery(true);
		$query->select('document_id, nbs_id, type, category, document_info');
		$query->from('#__nbs_id_check_documents');
		$query->where('nbs_id = ' . (int)$oldNbsId);
		$query->where('verified_status != -1');
		$db->setQuery($query);
		$nbsIdCheckDocuments = $db->loadAssocList();

		if (empty($nbsIdCheckDocuments)) {
			return true;
		}

		$documentIds = array();
		foreach ($nbsIdCheckDocuments as $document) {
			$documentIds[] = $document['document_id'];
		}
		$documentIds = implode(',', $documentIds);

		// Fetch documents details
		$query = $db->getQuery(true);
		$query->select('id, user_id, agency_id, property_id, list_id, list_type, title, path, category, type, size, extra, state');
		$query->from('#__documents');
		$query->where('id IN (' . $documentIds . ')');
		$query->where('state = 1');
		$query->where('list_id = ' . (int)$oldNbsId);
		$query->where('list_type = "nbs"');;
		$db->setQuery($query);
		$documents = $db->loadAssocList();

		foreach ($documents as &$document) {
			$document['oldId'] = $document['id'];
			$document['id'] = '';
			$document['list_id'] = $newNbsId;
		}

		// Save new documents
		$tableData = array(
			'unique_keys' => array('list_id','path'),
			'table' => 'documents',
			'data' => $documents
		);
		$actionFieldModel = JModel::getInstance('ActionField', 'JentlaContentModel');
		if (!$result = $actionFieldModel->saveTable($tableData)) {
			$this->setError($actionFieldModel->getError());
			return false;
		}

		$newDocuments = JArrayHelper::getValue($result, 'data');
		$documentIdsMap = array();
		foreach ($newDocuments as $newDocument) {
			$documentIdsMap[$newDocument['oldId']] = $newDocument['id'];
		}

		// Fetch documents map
		$query = $db->getQuery(true);
		$query->select('document_id, user_id, content, content_id, doc_type_id, doc_subtype_id');
		$query->from('#__documents_map');
		$query->where('document_id IN (' . $documentIds . ')');;
		$db->setQuery($query);
		$documentsMaps = $db->loadAssocList();

		foreach ($nbsIdCheckDocuments as &$nbsIdCheckDocument) {
			$nbsIdCheckDocument['id'] = '';
			$nbsIdCheckDocument['nbs_id'] = $newNbsId;
			$nbsIdCheckDocument['document_id'] = $documentIdsMap[$nbsIdCheckDocument['document_id']];
		}

		foreach ($documentsMaps as &$documentsMap) {
			$documentsMap['document_id'] = $documentIdsMap[$documentsMap['document_id']];
		}

		// Save new documents map and ID check documents
		$tables = array(
			array('table' => 'nbs_id_check_documents', 'unique_keys' => array('nbs_id','document_id'), 'data' => $nbsIdCheckDocuments),
			array('table' => 'documents_map', 'unique_keys' => array('document_id'), 'data' => $documentsMaps)
		);

		if (!$result = $actionFieldModel->saveTables($tables, 1)) {
			$this->setError($actionFieldModel->getError());
			return false;
		}

		return true;
	}
}