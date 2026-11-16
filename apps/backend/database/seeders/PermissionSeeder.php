<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Seed canonical permissions catalog from contracts/permissions.yaml.
     *
     * Idempotent: uses updateOrCreate on permission code.
     */
    public function run(): void
    {
        $catalog = $this->getPermissionCatalog();

        foreach ($catalog as $item) {
            Permission::updateOrCreate(
                ['code' => $item['code']],
                [
                    'module' => $item['module'],
                    'name' => $item['name'],
                    'description' => $item['description'] ?? null,
                ]
            );
        }
    }

    /**
     * Returns the approved Production V1 permission definitions.
     *
     * Primary source: contracts/permissions.yaml
     * Future disabled modules (door, visitor, vehicle, alpr, ai_assistant) are excluded.
     *
     * @return list<array{code: string, module: string, name: string, description: string}>
     */
    public function getPermissionCatalog(): array
    {
        return [
            // dashboard
            [
                'code' => 'dashboard.view',
                'module' => 'dashboard',
                'name' => 'View Dashboard',
                'description' => 'View system overview and operational metrics dashboard',
            ],

            // student
            [
                'code' => 'student.view',
                'module' => 'student',
                'name' => 'View Students',
                'description' => 'View student profiles and academic information',
            ],
            [
                'code' => 'student.create',
                'module' => 'student',
                'name' => 'Create Student',
                'description' => 'Create new student profiles',
            ],
            [
                'code' => 'student.update',
                'module' => 'student',
                'name' => 'Update Student',
                'description' => 'Update existing student profile details',
            ],
            [
                'code' => 'student.status.change',
                'module' => 'student',
                'name' => 'Change Student Status',
                'description' => 'Modify student academic or enrollment status',
            ],
            [
                'code' => 'student.import',
                'module' => 'student',
                'name' => 'Import Students',
                'description' => 'Batch import student data from external sources',
            ],
            [
                'code' => 'student.export',
                'module' => 'student',
                'name' => 'Export Students',
                'description' => 'Export student records to external files',
            ],
            [
                'code' => 'student.archive',
                'module' => 'student',
                'name' => 'Archive Student',
                'description' => 'Archive inactive or graduated student profiles',
            ],
            [
                'code' => 'student.sync',
                'module' => 'student',
                'name' => 'Sync Students from RMS',
                'description' => 'Run and review the RMS student and staff sync',
            ],

            // academic
            [
                'code' => 'academic.view',
                'module' => 'academic',
                'name' => 'View Academic Data',
                'description' => 'View departments, programs, and classroom structures',
            ],
            [
                'code' => 'academic.manage',
                'module' => 'academic',
                'name' => 'Manage Academic Data',
                'description' => 'Create and update academic structures and hierarchies',
            ],

            // face
            [
                'code' => 'face.view',
                'module' => 'face',
                'name' => 'View Face Profiles',
                'description' => 'View enrolled biometric face profile metadata',
            ],
            [
                'code' => 'face.enroll',
                'module' => 'face',
                'name' => 'Enroll Face',
                'description' => 'Enroll face samples and generate biometric embeddings',
            ],
            [
                'code' => 'face.revoke',
                'module' => 'face',
                'name' => 'Revoke Face',
                'description' => 'Revoke active face profile biometric enrollment',
            ],
            [
                'code' => 'face.reenroll',
                'module' => 'face',
                'name' => 'Re-enroll Face',
                'description' => 'Re-enroll updated face biometric samples',
            ],
            [
                'code' => 'face.test',
                'module' => 'face',
                'name' => 'Test Face Recognition',
                'description' => 'Execute restricted face recognition matching tests',
            ],
            [
                'code' => 'face.model.view',
                'module' => 'face',
                'name' => 'View Face Models',
                'description' => 'View face recognition model registry and versions',
            ],
            [
                'code' => 'face.model.manage',
                'module' => 'face',
                'name' => 'Manage Face Models',
                'description' => 'Manage face recognition models and active versions',
            ],

            // camera
            [
                'code' => 'camera.view',
                'module' => 'camera',
                'name' => 'View Cameras',
                'description' => 'View registered camera configurations and statuses',
            ],
            [
                'code' => 'camera.create',
                'module' => 'camera',
                'name' => 'Create Camera',
                'description' => 'Register new camera feeds and stream settings',
            ],
            [
                'code' => 'camera.update',
                'module' => 'camera',
                'name' => 'Update Camera',
                'description' => 'Update camera stream configurations and parameters',
            ],
            [
                'code' => 'camera.enable',
                'module' => 'camera',
                'name' => 'Enable Camera',
                'description' => 'Enable or disable camera feeds and processing',
            ],
            [
                'code' => 'camera.live',
                'module' => 'camera',
                'name' => 'View Camera Live Stream',
                'description' => 'Access live video streams from registered cameras',
            ],
            [
                'code' => 'camera.test',
                'module' => 'camera',
                'name' => 'Test Camera Feed',
                'description' => 'Test camera connection and RTSP stream connectivity',
            ],

            // ai_node
            [
                'code' => 'ai_node.view',
                'module' => 'ai_node',
                'name' => 'View AI Nodes',
                'description' => 'View edge AI processing nodes and health metrics',
            ],
            [
                'code' => 'ai_node.manage',
                'module' => 'ai_node',
                'name' => 'Manage AI Nodes',
                'description' => 'Register, configure, and manage edge AI nodes',
            ],

            // monitor
            [
                'code' => 'monitor.view',
                'module' => 'monitor',
                'name' => 'View Live Monitor',
                'description' => 'Access live security monitoring and event stream UI',
            ],

            // event
            [
                'code' => 'event.view',
                'module' => 'event',
                'name' => 'View Events',
                'description' => 'View access and recognition events history',
            ],
            [
                'code' => 'event.review',
                'module' => 'event',
                'name' => 'Review Events',
                'description' => 'Review and decide pending or ambiguous access events',
            ],
            [
                'code' => 'event.note',
                'module' => 'event',
                'name' => 'Add Event Notes',
                'description' => 'Add contextual notes and remarks to access events',
            ],

            // policy
            [
                'code' => 'policy.view',
                'module' => 'policy',
                'name' => 'View Access Policies',
                'description' => 'View access control rules and security policies',
            ],
            [
                'code' => 'policy.manage',
                'module' => 'policy',
                'name' => 'Manage Access Policies',
                'description' => 'Create, update, and configure access policies',
            ],

            // report
            [
                'code' => 'report.view',
                'module' => 'report',
                'name' => 'View Reports',
                'description' => 'View analytics, attendance, and security reports',
            ],
            [
                'code' => 'report.export',
                'module' => 'report',
                'name' => 'Export Reports',
                'description' => 'Export report data to CSV, XLSX, or PDF formats',
            ],
            [
                'code' => 'report.executive',
                'module' => 'report',
                'name' => 'View Executive Reports',
                'description' => 'Access high-level executive summary dashboards',
            ],
            [
                'code' => 'report.system',
                'module' => 'report',
                'name' => 'View System Reports',
                'description' => 'Access system health, audit, and diagnostic reports',
            ],

            // user
            [
                'code' => 'user.view',
                'module' => 'user',
                'name' => 'View Users',
                'description' => 'View user accounts and profile information',
            ],
            [
                'code' => 'user.create',
                'module' => 'user',
                'name' => 'Create User',
                'description' => 'Create new user login accounts',
            ],
            [
                'code' => 'user.update',
                'module' => 'user',
                'name' => 'Update User',
                'description' => 'Update user account details and credentials',
            ],
            [
                'code' => 'user.disable',
                'module' => 'user',
                'name' => 'Disable User',
                'description' => 'Disable or lock user login accounts',
            ],

            // role
            [
                'code' => 'role.view',
                'module' => 'role',
                'name' => 'View Roles',
                'description' => 'View system roles and permission assignments',
            ],
            [
                'code' => 'role.manage',
                'module' => 'role',
                'name' => 'Manage Roles',
                'description' => 'Create, edit, and assign roles and permissions',
            ],

            // audit
            [
                'code' => 'audit.view',
                'module' => 'audit',
                'name' => 'View Audit Logs',
                'description' => 'View system and administrative audit trails',
            ],

            // system
            [
                'code' => 'system.view',
                'module' => 'system',
                'name' => 'View System Settings',
                'description' => 'View platform system settings and configuration',
            ],
            [
                'code' => 'system.manage',
                'module' => 'system',
                'name' => 'Manage System Settings',
                'description' => 'Modify platform configurations and system parameters',
            ],
            [
                // ACR-OPS-01: SUPER_ADMIN only (see RolePermissionSeeder::SUPER_ADMIN_ONLY).
                'code' => 'system.update',
                'module' => 'system',
                'name' => 'Update System Version',
                'description' => 'View available releases and start a system update from the web app',
            ],
            [
                // ACR-INT-02: SUPER_ADMIN only (see RolePermissionSeeder::SUPER_ADMIN_ONLY).
                'code' => 'integration.manage',
                'module' => 'system',
                'name' => 'Manage External Integrations',
                'description' => 'Set and test external API connections (RMS) and their API keys',
            ],
            // Module 09 people (PPL-002, OWNER-DECISIONS-2026-11-16): staff, guardians, visitors, visits.
            [
                'code' => 'staff.view',
                'module' => 'staff',
                'name' => 'View Staff',
                'description' => 'View teacher and staff profiles',
            ],
            [
                'code' => 'staff.create',
                'module' => 'staff',
                'name' => 'Create Staff',
                'description' => 'Create teacher and staff profiles',
            ],
            [
                'code' => 'staff.update',
                'module' => 'staff',
                'name' => 'Update Staff',
                'description' => 'Update teacher and staff profile details',
            ],
            [
                'code' => 'staff.status.change',
                'module' => 'staff',
                'name' => 'Change Staff Status',
                'description' => 'Change the employment status of a teacher or staff member',
            ],
            [
                'code' => 'staff.import',
                'module' => 'staff',
                'name' => 'Import Staff',
                'description' => 'Batch import teachers and staff from a file',
            ],
            [
                'code' => 'guardian.view',
                'module' => 'guardian',
                'name' => 'View Guardians',
                'description' => 'View parents/guardians and their student links',
            ],
            [
                'code' => 'guardian.manage',
                'module' => 'guardian',
                'name' => 'Manage Guardians',
                'description' => 'Create guardians and link them to students',
            ],
            [
                'code' => 'visitor.view',
                'module' => 'visitor',
                'name' => 'View Visitors',
                'description' => 'View visitor and contractor profiles',
            ],
            [
                'code' => 'visitor.manage',
                'module' => 'visitor',
                'name' => 'Manage Visitors',
                'description' => 'Create and update visitor and contractor profiles',
            ],
            [
                'code' => 'visit.view',
                'module' => 'visitor',
                'name' => 'View Visits',
                'description' => 'View planned and current visits',
            ],
            [
                'code' => 'visit.manage',
                'module' => 'visitor',
                'name' => 'Manage Visits',
                'description' => 'Create visits and check visitors in and out',
            ],
        ];
    }
}
