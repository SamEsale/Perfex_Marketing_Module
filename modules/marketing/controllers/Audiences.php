<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Audiences extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        if (staff_cant('view', 'marketing') && staff_cant('view_own', 'marketing')) {
            access_denied('marketing');
        }

        $this->load->model('marketing/Marketing_audiences_model', 'marketing_audiences_model');
        $this->load->library('form_validation');
    }

    public function index()
    {
        if ($this->input->post('delete_audience_id')) {
            if (staff_cant('delete', 'marketing')) {
                access_denied('marketing');
            }

            $audienceId = (int) $this->input->post('delete_audience_id');

            if ($audienceId <= 0 || !$this->marketing_audiences_model->list_exists($audienceId)) {
                set_alert('danger', _l('marketing_audience_not_found'));
                redirect(admin_url('marketing/audiences'));
            }

            if ($this->marketing_audiences_model->count_members($audienceId) > 0) {
                set_alert('warning', _l('marketing_audience_delete_has_members'));
                redirect(admin_url('marketing/audiences'));
            }

            if ($this->marketing_audiences_model->delete_list($audienceId)) {
                set_alert('success', _l('marketing_audience_deleted'));
            } else {
                set_alert('danger', _l('marketing_audience_delete_failed'));
            }

            redirect(admin_url('marketing/audiences'));
        }

        $data['title']       = _l('marketing_audiences');
        $data['lists']       = $this->marketing_audiences_model->get_all_lists();
        $data['totals']      = [
            'lists'    => $this->marketing_audiences_model->count_lists(),
            'contacts' => $this->marketing_audiences_model->count_contacts(),
        ];
        $data['can_create']  = staff_can('create', 'marketing');
        $data['can_edit']    = staff_can('edit', 'marketing');
        $data['can_delete']  = staff_can('delete', 'marketing');

        $this->load->view('marketing/audiences/index', $data);
    }

    public function create()
    {
        if (staff_cant('create', 'marketing')) {
            access_denied('marketing');
        }

        if ($this->input->post()) {
            if ($this->save_list()) {
                redirect(admin_url('marketing/audiences'));
            }

            $data['list'] = [
                'id' => '',
                'name' => $this->input->post('name', true),
                'description' => $this->input->post('description', true),
                'source_mode' => $this->input->post('source_mode', true),
            ];
        } else {
            $data['list'] = [
                'id'          => '',
                'name'        => '',
                'description' => '',
                'source_mode' => 'manual',
            ];
        }

        $data['title'] = _l('marketing_create_audience');
        $data['action'] = admin_url('marketing/audiences/create');

        $this->load->view('marketing/audiences/form', $data);
    }

    public function edit($id)
    {
        if (staff_cant('edit', 'marketing')) {
            access_denied('marketing');
        }

        $list = $this->marketing_audiences_model->get_list($id);

        if (!$list) {
            show_404();
        }

        if ($this->input->post()) {
            if ($this->save_list($id)) {
                redirect(admin_url('marketing/audiences'));
            }

            $data['list'] = [
                'id' => $id,
                'name' => $this->input->post('name', true),
                'description' => $this->input->post('description', true),
                'source_mode' => $this->input->post('source_mode', true),
            ];
        } else {
            $data['list'] = $list;
        }

        $data['title'] = _l('marketing_edit_audience');
        $data['action'] = admin_url('marketing/audiences/edit/' . $id);

        $this->load->view('marketing/audiences/form', $data);
    }

    public function delete($id)
    {
        if (staff_cant('delete', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        if (!$this->marketing_audiences_model->get_list($id)) {
            show_404();
        }

        if ($this->marketing_audiences_model->delete_list($id)) {
            set_alert('success', _l('marketing_list_deleted'));
        } else {
            set_alert('warning', _l('marketing_list_delete_has_members'));
        }

        redirect(admin_url('marketing/audiences'));
    }

    public function members($id)
    {
        $canManageContacts = staff_can('create', 'marketing') || staff_can('edit', 'marketing') || staff_can('import', 'marketing');

        if ($this->input->post('add_contact')) {
            if (!$canManageContacts) {
                access_denied('marketing');
            }

            $list = $this->marketing_audiences_model->get_list($id);
            if (!$list) {
                show_404();
            }

            $contactForm = $this->get_new_contact_form_data();
            $email = mb_strtolower(trim((string) $this->input->post('email', true)));

            if ($email === '') {
                set_alert('danger', _l('marketing_contact_email_required'));
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                set_alert('danger', _l('marketing_contact_add_failed'));
            } else {
                $existing = $this->marketing_audiences_model->get_contact_by_normalized_email($email);

                if ($existing && $this->marketing_audiences_model->member_exists($id, $existing['id'])) {
                    set_alert('warning', _l('marketing_contact_already_in_audience'));
                    redirect(admin_url('marketing/audiences/members/' . $id));
                }

                $contactId = $this->marketing_audiences_model->upsert_contact([
                    'email' => $email,
                    'first_name' => $contactForm['first_name'],
                    'last_name' => $contactForm['last_name'],
                    'full_name' => $contactForm['full_name'],
                    'phone' => $contactForm['phone'],
                    'company' => $contactForm['company'],
                    'status' => $contactForm['status'],
                    'consent_status' => $contactForm['consent_status'],
                    'source_type' => 'manual',
                ]);

                if (!$contactId) {
                    set_alert('danger', _l('marketing_contact_add_failed'));
                } elseif ($this->marketing_audiences_model->add_contact_to_list($id, $contactId, 'manual', 'active')) {
                    set_alert('success', _l('marketing_contact_added_to_audience'));
                    redirect(admin_url('marketing/audiences/members/' . $id));
                } else {
                    set_alert('danger', _l('marketing_contact_add_failed'));
                }
            }
        }

        if ($this->input->post('bulk_remove_members')) {
            if (staff_cant('delete', 'marketing')) {
                access_denied('marketing');
            }

            $list = $this->marketing_audiences_model->get_list($id);
            if (!$list) {
                show_404();
            }

            $contactIds = $this->input->post('member_ids');
            if (!is_array($contactIds)) {
                $contactIds = [];
            }

            $removed = $this->marketing_audiences_model->bulk_remove_members($id, $contactIds);

            if ($removed > 0) {
                set_alert('success', sprintf(_l('marketing_bulk_members_removed'), $removed));
            } else {
                set_alert('warning', _l('marketing_bulk_members_remove_none'));
            }

            redirect(admin_url('marketing/audiences/members/' . $id));
        }

        if ($this->input->post('remove_member_contact_id')) {
            if (staff_cant('edit', 'marketing')) {
                access_denied('marketing');
            }

            $contactId = (int) $this->input->post('remove_member_contact_id');
            $listId    = (int) $id;

            if ($contactId > 0 && $this->marketing_audiences_model->remove_contact_from_list($listId, $contactId)) {
                set_alert('success', _l('marketing_contact_removed_from_audience'));
            } else {
                set_alert('warning', _l('marketing_contact_remove_failed'));
            }

            redirect(admin_url('marketing/audiences/members/' . $listId));
        }

        if (staff_cant('view', 'marketing') && staff_cant('view_own', 'marketing')) {
            access_denied('marketing');
        }

        $list = $this->marketing_audiences_model->get_list($id);

        if (!$list) {
            show_404();
        }

        $data['title'] = _l('marketing_audience_members');
        $data['list'] = $list;
        $data['members'] = $this->marketing_audiences_model->get_list_members($id);
        $data['can_edit'] = staff_can('edit', 'marketing');
        $data['can_delete'] = staff_can('delete', 'marketing');
        $data['can_add_contact'] = $canManageContacts;
        $data['add_contact_action'] = admin_url('marketing/audiences/members/' . $id);
        $data['contact_form'] = isset($contactForm) ? $contactForm : [
            'email' => '',
            'first_name' => '',
            'last_name' => '',
            'full_name' => '',
            'phone' => '',
            'company' => '',
            'status' => 'active',
            'consent_status' => 'subscribed',
        ];
        $data['status_options'] = $this->marketing_audiences_model->get_contact_status_options();
        $data['consent_status_options'] = $this->marketing_audiences_model->get_consent_status_options();

        $this->load->view('marketing/audiences/members', $data);
    }

    public function edit_contact($contactId)
    {
        if (staff_cant('edit', 'marketing')) {
            access_denied('marketing');
        }

        $contact = $this->marketing_audiences_model->get_contact($contactId);

        if (!$contact) {
            show_404();
        }

        if ($this->input->post()) {
            if ($this->save_contact($contactId)) {
                redirect(admin_url('marketing/audiences/edit_contact/' . $contactId));
            }

            $contact = $this->get_contact_form_data($contactId);
        }

        $data['title'] = _l('marketing_edit_contact');
        $data['action'] = admin_url('marketing/audiences/edit_contact/' . $contactId);
        $data['contact'] = $contact;
        $data['status_options'] = $this->marketing_audiences_model->get_contact_status_options();
        $data['consent_status_options'] = $this->marketing_audiences_model->get_consent_status_options();

        $this->load->view('marketing/audiences/contact_form', $data);
    }

    public function remove_member($listId, $contactId)
    {
        if (staff_cant('delete', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        if (!$this->marketing_audiences_model->get_list($listId) || !$this->marketing_audiences_model->get_contact($contactId)) {
            show_404();
        }

        if ($this->marketing_audiences_model->remove_member($listId, $contactId)) {
            set_alert('success', _l('marketing_member_removed'));
        } else {
            set_alert('warning', _l('marketing_member_remove_failure'));
        }

        redirect(admin_url('marketing/audiences/members/' . $listId));
    }

    public function delete_contact($contactId)
    {
        if (staff_cant('delete', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        $contact = $this->marketing_audiences_model->get_contact($contactId);

        if (!$contact) {
            show_404();
        }

        if (!$this->marketing_audiences_model->can_delete_contact($contactId)) {
            set_alert('warning', _l('marketing_contact_delete_blocked'));
        } elseif ($this->marketing_audiences_model->delete_contact($contactId)) {
            set_alert('success', _l('marketing_contact_deleted'));
        } else {
            set_alert('danger', _l('marketing_contact_delete_failure'));
        }

        $redirectListId = (int) $this->input->post('list_id');
        if ($redirectListId > 0 && $this->marketing_audiences_model->get_list($redirectListId)) {
            redirect(admin_url('marketing/audiences/members/' . $redirectListId));
        }

        redirect(admin_url('marketing/audiences'));
    }

    protected function save_list($id = null)
    {
        $this->form_validation->set_rules('name', _l('marketing_name'), 'required|trim');

        if ($this->form_validation->run() === false) {
            set_alert('danger', _l('marketing_list_name_required'));
            return false;
        }

        $name = $this->input->post('name', true);
        $description = $this->input->post('description', true);
        $source_mode = $this->input->post('source_mode', true);

        if ($this->marketing_audiences_model->list_name_exists($name, $id)) {
            set_alert('danger', _l('marketing_list_name_unique'));
            return false;
        }

        $data = [
            'name'        => $name,
            'description' => $description,
            'source_mode' => in_array($source_mode, ['manual', 'csv', 'perfex_sync', 'mixed'], true) ? $source_mode : 'manual',
        ];

        if ($id) {
            if ($this->marketing_audiences_model->update_list($id, $data)) {
                set_alert('success', _l('marketing_list_saved'));
                return true;
            }
        } else {
            if ($this->marketing_audiences_model->create_list($data)) {
                set_alert('success', _l('marketing_list_saved'));
                return true;
            }
        }

        set_alert('danger', _l('marketing_list_save_error'));
        return false;
    }

    protected function save_contact($contactId)
    {
        $this->form_validation->set_rules('email', _l('marketing_email'), 'required|trim|valid_email');
        $this->form_validation->set_rules('status', _l('marketing_status'), 'required|trim');
        $this->form_validation->set_rules('consent_status', _l('marketing_consent_status'), 'required|trim');

        if ($this->form_validation->run() === false) {
            set_alert('danger', validation_errors());

            return false;
        }

        $email = mb_strtolower(trim($this->input->post('email', true)));

        if ($this->marketing_audiences_model->normalized_email_exists($email, $contactId)) {
            set_alert('danger', _l('marketing_contact_email_unique'));

            return false;
        }

        $data = [
            'email'            => $email,
            'normalized_email' => $email,
            'first_name'       => trim((string) $this->input->post('first_name', true)),
            'last_name'        => trim((string) $this->input->post('last_name', true)),
            'full_name'        => trim((string) $this->input->post('full_name', true)),
            'phone'            => trim((string) $this->input->post('phone', true)),
            'company'          => trim((string) $this->input->post('company', true)),
            'status'           => $this->marketing_audiences_model->sanitize_contact_status($this->input->post('status', true)),
            'consent_status'   => $this->marketing_audiences_model->sanitize_consent_status($this->input->post('consent_status', true)),
        ];

        if ($this->marketing_audiences_model->update_contact($contactId, $data)) {
            set_alert('success', _l('marketing_contact_saved'));

            return true;
        }

        set_alert('danger', _l('marketing_contact_save_error'));

        return false;
    }

    protected function get_contact_form_data($contactId)
    {
        return [
            'id'             => $contactId,
            'email'          => mb_strtolower(trim((string) $this->input->post('email', true))),
            'first_name'     => trim((string) $this->input->post('first_name', true)),
            'last_name'      => trim((string) $this->input->post('last_name', true)),
            'full_name'      => trim((string) $this->input->post('full_name', true)),
            'phone'          => trim((string) $this->input->post('phone', true)),
            'company'        => trim((string) $this->input->post('company', true)),
            'status'         => $this->marketing_audiences_model->sanitize_contact_status($this->input->post('status', true)),
            'consent_status' => $this->marketing_audiences_model->sanitize_consent_status($this->input->post('consent_status', true)),
        ];
    }

    protected function get_new_contact_form_data()
    {
        return [
            'email' => mb_strtolower(trim((string) $this->input->post('email', true))),
            'first_name' => trim((string) $this->input->post('first_name', true)),
            'last_name' => trim((string) $this->input->post('last_name', true)),
            'full_name' => trim((string) $this->input->post('full_name', true)),
            'phone' => trim((string) $this->input->post('phone', true)),
            'company' => trim((string) $this->input->post('company', true)),
            'status' => $this->marketing_audiences_model->sanitize_contact_status($this->input->post('status', true) ?: 'active'),
            'consent_status' => $this->marketing_audiences_model->sanitize_consent_status($this->input->post('consent_status', true) ?: 'subscribed'),
        ];
    }
}
