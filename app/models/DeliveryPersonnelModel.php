<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** delivery_personnel: people who pick up released supplies for a learning center. */
class DeliveryPersonnelModel extends Model
{
    protected $table = 'delivery_personnel';
    protected $primary_key = 'id';
    protected $fillable = ['training_center_id', 'full_name', 'contact_number', 'vehicle', 'plate_number', 'is_active'];

    /** Everyone (or one center's team), active people first. */
    public function listing($centerId = null)
    {
        $query = $this->db->table('delivery_personnel d')
            ->select('d.id, d.training_center_id, d.full_name, d.contact_number, d.vehicle, d.plate_number,
                      d.is_active, tc.name AS center_name')
            ->inner_join('training_centers tc', 'tc.id = d.training_center_id');
        if ($centerId) {
            $query->where('d.training_center_id', (int) $centerId);
        }
        $people = $query->order_by('d.full_name', 'ASC')->get_all();
        usort($people, fn ($a, $b) => (int) $b['is_active'] <=> (int) $a['is_active']);
        return $people;
    }

    /** The person, if active and on the center's team. */
    public function active_in_center($id, $centerId)
    {
        return $this->filter(['id' => (int) $id, 'training_center_id' => (int) $centerId, 'is_active' => 1])->get();
    }
}
