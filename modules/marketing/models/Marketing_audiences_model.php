<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_audiences_model extends App_Model
{
    const LISTS_TABLE = 'marketing_lists';
    const CONTACTS_TABLE = 'marketing_contacts';
    const MEMBERS_TABLE = 'marketing_list_members';
    const SUPPRESSIONS_TABLE = 'marketing_suppressions';
    const CAMPAIGN_RECIPIENTS_TABLE = 'marketing_campaign_recipients';
    const EVENTS_TABLE = 'marketing_events';
    const CONVERSATIONS_TABLE = 'marketing_conversations';

    protected $listsTable;
    protected $contactsTable;
    protected $membersTable;
    protected $suppressionsTable;
    protected $campaignRecipientsTable;
    protected $eventsTable;
    protected $conversationsTable;

    public function __construct()
    {
        parent::__construct();

        $this->listsTable        = db_prefix() . self::LISTS_TABLE;
        $this->contactsTable     = db_prefix() . self::CONTACTS_TABLE;
        $this->membersTable      = db_prefix() . self::MEMBERS_TABLE;
        $this->suppressionsTable = db_prefix() . self::SUPPRESSIONS_TABLE;
        $this->campaignRecipientsTable = db_prefix() . self::CAMPAIGN_RECIPIENTS_TABLE;
        $this->eventsTable = db_prefix() . self::EVENTS_TABLE;
        $this->conversationsTable = db_prefix() . self::CONVERSATIONS_TABLE;
    }

    public function count_lists()
    {
        return $this->db->count_all($this->listsTable);
    }

    public function count_contacts()
    {
        return $this->db->count_all($this->contactsTable);
    }

    public function count_memberships()
    {
        return $this->db->count_all($this->membersTable);
    }

    public function list_exists($id)
    {
        return total_rows($this->listsTable, ['id' => $id]) > 0;
    }

    public function list_name_exists($name, $excludeId = null)
    {
        $this->db->where('name', $name);

        if ($excludeId) {
            $this->db->where('id !=', $excludeId);
        }

        return $this->db->count_all_results($this->listsTable) > 0;
    }

    public function get_all_lists()
    {
        $this->db->select('l.*, COUNT(m.id) as members_count');
        $this->db->from($this->listsTable . ' l');
        $this->db->join($this->membersTable . ' m', 'm.list_id = l.id', 'left');
        $this->db->group_by('l.id');
        $this->db->order_by('l.id', 'DESC');

        return $this->db->get()->result_array();
    }

    public function get_list($id)
    {
        return $this->db->get_where($this->listsTable, ['id' => $id])->row_array();
    }

    public function get_list_members($listId)
    {
        $this->db->select('c.*, m.member_status, m.added_via, m.dateadded');
        $this->db->from($this->membersTable . ' m');
        $this->db->join($this->contactsTable . ' c', 'c.id = m.marketing_contact_id', 'left');
        $this->db->where('m.list_id', $listId);
        $this->db->order_by('c.email', 'ASC');

        return $this->db->get()->result_array();
    }

    public function count_list_members($listId)
    {
        return total_rows($this->membersTable, ['list_id' => $listId]);
    }

    public function create_list($data)
    {
        $data['created_by'] = get_staff_user_id();
        $data['datecreated'] = date('Y-m-d H:i:s');

        $this->db->insert($this->listsTable, $data);

        return $this->db->insert_id();
    }

    public function update_list($id, $data)
    {
        $data['datecreated'] = date('Y-m-d H:i:s');

        $this->db->where('id', $id);
        $this->db->update($this->listsTable, $data);

        return $this->db->affected_rows() !== 0;
    }

    public function delete_list($id)
    {
        if ($this->count_list_members($id) > 0) {
            return false;
        }

        $this->db->where('id', $id);
        $this->db->delete($this->listsTable);

        return $this->db->affected_rows() !== 0;
    }

    public function get_contact($contactId)
    {
        return $this->db->get_where($this->contactsTable, ['id' => $contactId])->row_array();
    }

    public function normalized_email_exists($normalizedEmail, $excludeId = null)
    {
        $this->db->where('normalized_email', $normalizedEmail);

        if ($excludeId !== null) {
            $this->db->where('id !=', $excludeId);
        }

        return $this->db->count_all_results($this->contactsTable) > 0;
    }

    public function update_contact($contactId, $data)
    {
        $data['datecreated'] = date('Y-m-d H:i:s');

        $this->db->where('id', $contactId);
        $this->db->update($this->contactsTable, $data);

        return $this->db->affected_rows() >= 0;
    }

    public function remove_member($listId, $contactId)
    {
        $this->db->where('list_id', $listId);
        $this->db->where('marketing_contact_id', $contactId);
        $this->db->delete($this->membersTable);

        return $this->db->affected_rows() > 0;
    }

    public function mark_contact_unsubscribed($contactId)
    {
        $contactId = (int) $contactId;
        if ($contactId <= 0 || !$this->db->table_exists($this->contactsTable)) {
            return false;
        }

        $data = $this->filter_existing_columns($this->contactsTable, [
            'status' => 'unsubscribed',
            'consent_status' => 'unsubscribed',
            'dateupdated' => date('Y-m-d H:i:s'),
        ]);

        if (empty($data)) {
            return false;
        }

        $this->db->where('id', $contactId);
        $this->db->update($this->contactsTable, $data);

        return $this->db->affected_rows() >= 0;
    }

    public function mark_list_membership_unsubscribed($listId, $contactId)
    {
        $listId = (int) $listId;
        $contactId = (int) $contactId;
        if ($listId <= 0 || $contactId <= 0 || !$this->db->table_exists($this->membersTable)) {
            return false;
        }

        if ($this->db->field_exists('member_status', $this->membersTable)) {
            $data = $this->filter_existing_columns($this->membersTable, [
                'member_status' => 'inactive',
                'dateupdated' => date('Y-m-d H:i:s'),
            ]);

            if (!empty($data)) {
                $this->db->where('list_id', $listId);
                $this->db->where('marketing_contact_id', $contactId);
                $this->db->update($this->membersTable, $data);

                return $this->db->affected_rows() >= 0;
            }
        }

        return $this->remove_member($listId, $contactId);
    }

    public function can_delete_contact($contactId)
    {
        if (total_rows($this->campaignRecipientsTable, ['marketing_contact_id' => $contactId]) > 0) {
            return false;
        }

        if (total_rows($this->eventsTable, ['marketing_contact_id' => $contactId]) > 0) {
            return false;
        }

        if (total_rows($this->conversationsTable, ['marketing_contact_id' => $contactId]) > 0) {
            return false;
        }

        return true;
    }

    public function delete_contact($contactId)
    {
        $this->db->where('marketing_contact_id', $contactId);
        $this->db->delete($this->membersTable);

        $this->db->where('id', $contactId);
        $this->db->delete($this->contactsTable);

        return $this->db->affected_rows() > 0;
    }

    public function get_contact_by_normalized_email($normalizedEmail)
    {
        return $this->db->get_where($this->contactsTable, ['normalized_email' => $normalizedEmail])->row_array();
    }

    public function upsert_contact($contact)
    {
        if (empty($contact['email'])) {
            return false;
        }

        $normalizedEmail = $this->normalize_email($contact['email']);

        if (!$normalizedEmail) {
            return false;
        }

        $existing = $this->get_contact_by_normalized_email($normalizedEmail);

        $data = [
            'email'            => trim($contact['email']),
            'normalized_email' => $normalizedEmail,
            'first_name'       => isset($contact['first_name']) ? trim($contact['first_name']) : null,
            'last_name'        => isset($contact['last_name']) ? trim($contact['last_name']) : null,
            'full_name'        => isset($contact['full_name']) ? trim($contact['full_name']) : null,
            'phone'            => isset($contact['phone']) ? trim($contact['phone']) : null,
            'company'          => isset($contact['company']) ? trim($contact['company']) : null,
            'status'           => isset($contact['status']) ? $this->sanitize_contact_status($contact['status']) : 'active',
            'consent_status'   => isset($contact['consent_status']) ? $this->sanitize_consent_status($contact['consent_status']) : 'unknown',
            'source_type'      => isset($contact['source_type']) ? $contact['source_type'] : 'csv',
        ];

        if ($existing && empty($contact['source_type'])) {
            unset($data['source_type']);
        }

        if ($existing) {
            $data['datecreated'] = date('Y-m-d H:i:s');
            $this->db->where('id', $existing['id']);
            $this->db->update($this->contactsTable, $data);

            return $existing['id'];
        }

        $data['datecreated'] = date('Y-m-d H:i:s');
        $this->db->insert($this->contactsTable, $data);

        return $this->db->insert_id();
    }

    public function add_contact_to_list($listId, $contactId, $addedVia = 'csv', $memberStatus = 'active')
    {
        $contact = $this->db->get_where($this->contactsTable, ['id' => $contactId])->row_array();

        if (!$contact) {
            return false;
        }

        if ($this->is_contact_suppressed($contact['normalized_email'])) {
            return false;
        }

        if ($this->member_exists($listId, $contactId)) {
            return true;
        }

        $this->db->insert($this->membersTable, [
            'list_id' => $listId,
            'marketing_contact_id' => $contactId,
            'member_status' => in_array($memberStatus, ['active', 'inactive'], true) ? $memberStatus : 'active',
            'added_via' => trim((string) $addedVia) !== '' ? trim((string) $addedVia) : 'csv',
            'dateadded' => date('Y-m-d H:i:s'),
        ]);

        return $this->db->insert_id() !== 0;
    }

    public function member_exists($listId, $contactId)
    {
        return total_rows($this->membersTable, ['list_id' => $listId, 'marketing_contact_id' => $contactId]) > 0;
    }

    public function is_contact_suppressed($normalizedEmail)
    {
        if (!$normalizedEmail) {
            return false;
        }

        $suppressed = false;
        if (
            $this->db->table_exists($this->suppressionsTable)
            && $this->db->field_exists('normalized_email', $this->suppressionsTable)
            && $this->db->field_exists('active', $this->suppressionsTable)
        ) {
            $suppressed = total_rows($this->suppressionsTable, ['normalized_email' => $normalizedEmail, 'active' => 1]) > 0;
        }

        if ($suppressed) {
            return true;
        }

        $contact = $this->get_contact_by_normalized_email($normalizedEmail);

        if (!$contact) {
            return false;
        }

        return in_array($contact['status'], ['suppressed', 'bounced', 'complained', 'unsubscribed'], true);
    }

    public function get_contact_status_options()
    {
        return ['active', 'unsubscribed', 'bounced', 'complained', 'suppressed'];
    }

    public function get_consent_status_options()
    {
        return ['unknown', 'subscribed', 'unsubscribed'];
    }

    public function sanitize_contact_status($status)
    {
        return in_array($status, $this->get_contact_status_options(), true) ? $status : 'active';
    }

    public function sanitize_consent_status($status)
    {
        return in_array($status, $this->get_consent_status_options(), true) ? $status : 'unknown';
    }

    protected function normalize_email($email)
    {
        return mb_strtolower(trim($email));
    }

    protected function filter_existing_columns($table, array $data)
    {
        if (!$this->db->table_exists($table)) {
            return [];
        }

        $columns = $this->db->list_fields($table);
        if (!is_array($columns) || empty($columns)) {
            return [];
        }

        return array_intersect_key($data, array_flip($columns));
    }

    public function count_members($listId)
    {
        return (int) $this->db
            ->where('list_id', (int) $listId)
            ->count_all_results(db_prefix() . 'marketing_list_members');
    }



    public function remove_contact_from_list($listId, $contactId)
    {
        $this->db->where('list_id', (int) $listId);
        $this->db->where('marketing_contact_id', (int) $contactId);

        return (bool) $this->db->delete(db_prefix() . 'marketing_list_members');
    }

    public function bulk_remove_members($listId, array $contactIds)
    {
        $contactIds = array_values(array_unique(array_map('intval', $contactIds)));
        $contactIds = array_filter($contactIds, function ($contactId) {
            return $contactId > 0;
        });

        if (empty($contactIds)) {
            return 0;
        }

        $this->db->where('list_id', (int) $listId);
        $this->db->where_in('marketing_contact_id', $contactIds);
        $this->db->delete($this->membersTable);

        return (int) $this->db->affected_rows();
    }


}
