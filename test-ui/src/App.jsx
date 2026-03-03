import KeyIcon from '@mui/icons-material/Key'
import PeopleIcon from '@mui/icons-material/People'
import SettingsIcon from '@mui/icons-material/Settings'
import {
  Alert,
  Box, Button,
  Checkbox,
  Chip, CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  FormControlLabel, FormGroup,
  Paper, Radio, RadioGroup, Table, TableBody, TableCell,
  TableContainer, TableHead, TableRow,
  TextField, Typography
} from '@mui/material'
import CssBaseline from '@mui/material/CssBaseline'
import { createTheme, ThemeProvider } from '@mui/material/styles'
import { useCallback, useEffect, useState } from 'react'

const API = 'http://localhost:8000/api'

const theme = createTheme({
  palette: {
    primary:    { main: '#2563eb' },
    error:      { main: '#dc2626' },
    success:    { main: '#16a34a' },
    warning:    { main: '#d97706' },
    background: { default: '#f1f5f9', paper: '#ffffff' },
  },
  typography: { fontFamily: 'system-ui, sans-serif' },
  shape: { borderRadius: 0 },
  components: {
    MuiButton:    { defaultProps: { disableElevation: true }, styleOverrides: { root: { textTransform: 'none', fontWeight: 600 } } },
    MuiTextField: { defaultProps: { size: 'small' } },
    MuiTableCell: { styleOverrides: { head: { fontWeight: 700, fontSize: 11, textTransform: 'uppercase', letterSpacing: 0.5, color: '#64748b', backgroundColor: '#f8fafc' } } },
  },
})

// ── API helper ────────────────────────────────────────────────────────────────

async function req(method, path, body) {
  const opts = {
    method,
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    credentials: 'include',
  }
  if (body !== undefined) opts.body = JSON.stringify(body)
  try {
    const res = await fetch(`${API}${path}`, opts)
    const data = await res.json()
    return { ok: res.ok, status: res.status, data }
  } catch (e) {
    return { ok: false, status: 0, data: { success: false, message: e.message } }
  }
}

// ── Sidebar config ────────────────────────────────────────────────────────────

const NAV = [
  { id: 'users',   label: 'Users',              Icon: PeopleIcon   },
  { id: 'roles',   label: 'Roles & Permissions', Icon: KeyIcon      },
  { id: 'profile', label: 'My Profile',          Icon: SettingsIcon },
]

// ── Permission helper ─────────────────────────────────────────────────────────

function getPerms(user) {
  const set = new Set()
  for (const category of Object.values(user?.permissions || {})) {
    for (const slugs of Object.values(category)) {
      if (Array.isArray(slugs)) slugs.forEach(s => set.add(s))
    }
  }
  return set
}

// ── Social login ──────────────────────────────────────────────────────────────

async function socialLogin(provider) {
  const r = await req('GET', `/auth/social/${provider}/redirect`)
  if (r.ok && r.data.success) window.location.href = r.data.url
}

const GoogleIcon = () => (
  <svg width="18" height="18" viewBox="0 0 18 18" style={{ flexShrink: 0 }}>
    <path fill="#4285F4" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.716v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.615z"/>
    <path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z"/>
    <path fill="#FBBC05" d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z"/>
    <path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58z"/>
  </svg>
)

const FacebookIcon = () => (
  <svg width="18" height="18" viewBox="0 0 24 24" fill="#1877F2" style={{ flexShrink: 0 }}>
    <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.236 2.686.236v2.97h-1.513c-1.491 0-1.956.93-1.956 1.886v2.267h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
  </svg>
)

function SocialButtons() {
  return (
    <>
      <Divider sx={{ my: 2 }}>
        <Typography variant="caption" color="text.secondary">or continue with</Typography>
      </Divider>
      <Box sx={{ display: 'flex', flexDirection: 'column', gap: 1, mb: 2 }}>
        <Button fullWidth variant="outlined" size="large" startIcon={<GoogleIcon />}
          onClick={() => socialLogin('google')}
          sx={{ justifyContent: 'flex-start', borderColor: '#e2e8f0', color: 'text.primary', '&:hover': { borderColor: '#94a3b8', bgcolor: '#f8fafc' } }}>
          Continue with Google
        </Button>
        <Button fullWidth variant="outlined" size="large" startIcon={<FacebookIcon />}
          onClick={() => socialLogin('facebook')}
          sx={{ justifyContent: 'flex-start', borderColor: '#e2e8f0', color: 'text.primary', '&:hover': { borderColor: '#94a3b8', bgcolor: '#f8fafc' } }}>
          Continue with Facebook
        </Button>
      </Box>
    </>
  )
}

// ── Auth pages ────────────────────────────────────────────────────────────────

function AuthCard({ title, children }) {
  return (
    <Box sx={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', bgcolor: 'background.default', p: 2 }}>
      <Paper elevation={0} sx={{ width: '100%', maxWidth: 420, p: 4, border: '1px solid #e2e8f0' }}>
        {/* Header row: logo left, page title right */}
        <Box sx={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', mb: 0.25 }}>
          <Typography variant="h5" fontWeight={800}>Socia</Typography>
          <Typography variant="h6" fontWeight={700}>{title}</Typography>
        </Box>
        <Typography variant="body2" color="text.secondary" mb={3}>User & Access Management</Typography>
        <Divider sx={{ mb: 3 }} />
        {children}
      </Paper>
    </Box>
  )
}

function LoginPage({ onLogin, goRegister, goForgot, initialError }) {
  const [f, setF]       = useState({ email: '', password: '' })
  const [error, setErr] = useState(initialError || '')
  const [loading, setL] = useState(false)
  const set = k => e => setF(p => ({ ...p, [k]: e.target.value }))

  const submit = async () => {
    setErr(''); setL(true)
    const r = await req('POST', '/auth/login', f)
    setL(false)
    if (r.ok && r.data.success) onLogin(r.data.data)
    else setErr(r.data.message || 'Invalid credentials.')
  }

  return (
    <AuthCard title="Sign in">
      {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
      <TextField fullWidth label="Email address" type="email" value={f.email} onChange={set('email')}
        onKeyDown={e => e.key === 'Enter' && submit()} sx={{ mb: 2 }} autoFocus />
      <TextField fullWidth label="Password" type="password" value={f.password} onChange={set('password')}
        onKeyDown={e => e.key === 'Enter' && submit()} sx={{ mb: 1 }} />
      <Box textAlign="right" mb={2}>
        <Button size="small" onClick={goForgot} sx={{ p: 0, minWidth: 0 }}>Forgot password?</Button>
      </Box>
      <Button fullWidth variant="contained" size="large" onClick={submit} disabled={loading}>
        {loading ? <CircularProgress size={20} color="inherit" /> : 'Sign In'}
      </Button>
      <SocialButtons />
      <Typography variant="body2" color="text.secondary" textAlign="center">
        Don't have an account?{' '}
        <Button size="small" onClick={goRegister} sx={{ p: 0, minWidth: 0, verticalAlign: 'baseline' }}>Register</Button>
      </Typography>
    </AuthCard>
  )
}

function RegisterPage({ goLogin }) {
  const [f, setF]       = useState({ name: '', email: '', password: '', password_confirmation: '' })
  const [errs, setErrs] = useState({})
  const [alert, setA]   = useState(null)
  const [loading, setL] = useState(false)
  const set = k => e => setF(p => ({ ...p, [k]: e.target.value }))

  const submit = async () => {
    setErrs({}); setA(null); setL(true)
    const r = await req('POST', '/auth/register', f)
    setL(false)
    if (r.ok && r.data.success) {
      setA({ severity: 'success', msg: r.data.message || 'Account created! Check your email.' })
      setF({ name: '', email: '', password: '', password_confirmation: '' })
    } else {
      if (r.data.errors) setErrs(r.data.errors)
      else setA({ severity: 'error', msg: r.data.message || 'Registration failed.' })
    }
  }

  return (
    <AuthCard title="Create account">
      {alert && <Alert severity={alert.severity} sx={{ mb: 2 }}>{alert.msg}</Alert>}
      <TextField fullWidth label="Full name"        value={f.name}  onChange={set('name')}  sx={{ mb: 2 }} autoFocus
        error={!!errs.name?.[0]}  helperText={errs.name?.[0]} />
      <TextField fullWidth label="Email address"    value={f.email} onChange={set('email')}  sx={{ mb: 2 }} type="email"
        error={!!errs.email?.[0]} helperText={errs.email?.[0]} />
      <TextField fullWidth label="Password"         value={f.password} onChange={set('password')} sx={{ mb: 2 }} type="password"
        error={!!errs.password?.[0]} helperText={errs.password?.[0] || 'Min 8 characters'} />
      <TextField fullWidth label="Confirm password" value={f.password_confirmation} onChange={set('password_confirmation')} sx={{ mb: 2 }} type="password" />
      <SocialButtons />
      <Button fullWidth variant="contained" size="large" onClick={submit} disabled={loading}>
        {loading ? <CircularProgress size={20} color="inherit" /> : 'Create Account'}
      </Button>
      <Typography variant="body2" color="text.secondary" textAlign="center" mt={2}>
        Already have an account?{' '}
        <Button size="small" onClick={goLogin} sx={{ p: 0, minWidth: 0, verticalAlign: 'baseline' }}>Sign in</Button>
      </Typography>
    </AuthCard>
  )
}

function ForgotPage({ goLogin }) {
  const [email, setEmail] = useState('')
  const [alert, setA]     = useState(null)
  const [loading, setL]   = useState(false)

  const submit = async () => {
    setA(null); setL(true)
    const r = await req('POST', '/auth/forgot-password', { email })
    setL(false)
    setA({ severity: r.data.success ? 'success' : 'error', msg: r.data.message || 'Done.' })
  }

  return (
    <AuthCard title="Reset password">
      {alert && <Alert severity={alert.severity} sx={{ mb: 2 }}>{alert.msg}</Alert>}
      <TextField fullWidth label="Email address" type="email" value={email}
        onChange={e => setEmail(e.target.value)} sx={{ mb: 3 }} autoFocus
        onKeyDown={e => e.key === 'Enter' && submit()} />
      <Button fullWidth variant="contained" size="large" onClick={submit} disabled={loading}>
        {loading ? <CircularProgress size={20} color="inherit" /> : 'Send Reset Link'}
      </Button>
      <Box textAlign="center" mt={2}>
        <Button size="small" onClick={goLogin} sx={{ p: 0, minWidth: 0 }}>← Back to sign in</Button>
      </Box>
    </AuthCard>
  )
}

function ResetPasswordPage({ goLogin }) {
  const params = new URLSearchParams(window.location.search)
  const [f, setF]   = useState({
    token: params.get('token') || '',
    email: params.get('email') || '',
    password: '',
    password_confirmation: '',
  })
  const [alert, setA] = useState(null)
  const [loading, setL] = useState(false)
  const set = k => e => setF(p => ({ ...p, [k]: e.target.value }))

  const submit = async () => {
    setA(null); setL(true)
    const r = await req('POST', '/auth/reset-password', f)
    setL(false)
    setA({ severity: r.data.success ? 'success' : 'error', msg: r.data.message || 'Done.' })
  }

  return (
    <AuthCard title="New password">
      {alert && <Alert severity={alert.severity} sx={{ mb: 2 }}>{alert.msg}</Alert>}
      <TextField fullWidth label="Email address" type="email" value={f.email}
        onChange={set('email')} sx={{ mb: 2 }} />
      <TextField fullWidth label="New password" type="password" value={f.password}
        onChange={set('password')} sx={{ mb: 2 }} autoFocus
        onKeyDown={e => e.key === 'Enter' && submit()} />
      <TextField fullWidth label="Confirm new password" type="password" value={f.password_confirmation}
        onChange={set('password_confirmation')} sx={{ mb: 3 }}
        onKeyDown={e => e.key === 'Enter' && submit()} />
      <Button fullWidth variant="contained" size="large" onClick={submit} disabled={loading}>
        {loading ? <CircularProgress size={20} color="inherit" /> : 'Reset Password'}
      </Button>
      <Box textAlign="center" mt={2}>
        <Button size="small" onClick={goLogin} sx={{ p: 0, minWidth: 0 }}>← Back to sign in</Button>
      </Box>
    </AuthCard>
  )
}

// ── Dashboard shell ───────────────────────────────────────────────────────────

function Dashboard({ user, onLogout }) {
  const perms = getPerms(user)
  const navVisible = { users: perms.has('users.view'), roles: perms.has('roles.view'), profile: true }
  const defaultSection = navVisible.users ? 'users' : navVisible.roles ? 'roles' : 'profile'
  const [section, setSection] = useState(defaultSection)

  return (
    <Box sx={{ display: 'flex', height: '100vh', overflow: 'hidden' }}>

      {/* Sidebar */}
      <Box sx={{
        width: 232, flexShrink: 0, bgcolor: '#0f172a',
        display: 'flex', flexDirection: 'column', height: '100vh',
      }}>
        {/* Logo */}
        <Box sx={{ px: 2.5, py: 2.5, borderBottom: '1px solid rgba(255,255,255,0.07)' }}>
          <Typography fontWeight={800} fontSize={18} color="white" letterSpacing={-0.3}>Socia</Typography>
          <Typography fontSize={11} color="rgba(255,255,255,0.4)" mt={0.25}>Management Console</Typography>
        </Box>

        {/* Nav */}
        <Box sx={{ flex: 1, py: 1 }}>
          {NAV.filter(item => navVisible[item.id]).map(item => {
            const active = section === item.id
            return (
              <Box key={item.id} onClick={() => setSection(item.id)} sx={{
                display: 'flex', alignItems: 'center', gap: 1.25,
                px: 0, py: 1.25, mb: 0.25, pl: 2.5,
                cursor: 'pointer', transition: 'background 0.12s',
                bgcolor: active ? '#1e40af' : 'transparent',
                color: active ? 'white' : 'rgba(255,255,255,0.55)',
                fontSize: 14, fontWeight: active ? 600 : 400,
                '&:hover': { bgcolor: active ? '#1e40af' : 'rgba(255,255,255,0.06)' },
              }}>
                <item.Icon sx={{ fontSize: 18 }} />
                {item.label}
              </Box>
            )
          })}
        </Box>

        {/* User + logout */}
        <Box sx={{ px: 1.25, py: 2, borderTop: '1px solid rgba(255,255,255,0.07)' }}>
          <Box sx={{ px: 1.5, py: 1, mb: 1 }}>
            <Typography fontSize={13} fontWeight={600} color="white" noWrap>{user?.name}</Typography>
            <Typography fontSize={11} color="rgba(255,255,255,0.4)" noWrap>{user?.email}</Typography>
          </Box>
          <Button fullWidth onClick={onLogout} sx={{
            color: '#f87171', bgcolor: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.2)',
            justifyContent: 'flex-start', px: 1.5, fontSize: 13,
            '&:hover': { bgcolor: 'rgba(239,68,68,0.18)' },
          }}>
            → Sign out
          </Button>
        </Box>
      </Box>

      {/* Main */}
      <Box sx={{ flex: 1, display: 'flex', flexDirection: 'column', overflow: 'hidden', bgcolor: 'background.default' }}>
        {section === 'users'   && <UsersSection perms={perms} />}
        {section === 'roles'   && <RolesSection perms={perms} />}
        {section === 'profile' && <ProfileSection user={user} onLogout={onLogout} />}
      </Box>
    </Box>
  )
}

// ── Users section ─────────────────────────────────────────────────────────────

function UsersSection({ perms }) {
  const [users, setUsers]   = useState([])
  const [meta,  setMeta]    = useState(null)
  const [roles, setRoles]   = useState([])
  const [loading, setL]     = useState(true)
  const [error, setError]   = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage]     = useState(1)
  const [modal, setModal]   = useState(null)
  const close = () => setModal(null)

  const loadUsers = useCallback(async () => {
    setL(true); setError('')
    const params = new URLSearchParams({ page })
    if (search) params.set('search', search)
    const r = await req('GET', `/users?${params}`)
    setL(false)
    if (r.ok && r.data.success) {
      setUsers(r.data.data?.users || [])
      setMeta(r.data.data?.pagination || null)
    } else setError(r.data.message || 'Failed to load users.')
  }, [search, page])

  const loadRoles = useCallback(async () => {
    const r = await req('GET', '/roles')
    if (r.ok && r.data.success) setRoles(r.data.data || [])
  }, [])

  useEffect(() => { loadUsers() }, [loadUsers])
  useEffect(() => { loadRoles() }, [loadRoles])

  const refresh = () => { close(); loadUsers() }

  return (
    <Box sx={{ display: 'flex', flexDirection: 'column', height: '100%', overflow: 'hidden' }}>

      {/* Header */}
      <Box sx={{ px: 3, py: 2, bgcolor: 'background.paper', borderBottom: '1px solid #e2e8f0', display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexShrink: 0 }}>
        <Box>
          <Typography variant="h6" fontWeight={700}>Users</Typography>
          <Typography variant="caption" color="text.secondary">Manage user accounts and access permissions.</Typography>
        </Box>
        {perms.has('users.create') && <Button variant="contained" onClick={() => setModal({ type: 'create' })}>+ Add User</Button>}
      </Box>

      {/* Search */}
      <Box sx={{ px: 3, py: 1.5, bgcolor: 'background.paper', borderBottom: '1px solid #e2e8f0', flexShrink: 0, display: 'flex', gap: 1 }}>
        <TextField
          fullWidth placeholder="Search by name or email…" value={search}
          onChange={e => { setSearch(e.target.value); setPage(1) }}
          onKeyDown={e => e.key === 'Enter' && loadUsers()}
        />
        <Button variant="outlined" onClick={loadUsers}>Search</Button>
        {search && <Button variant="text" onClick={() => { setSearch(''); setPage(1) }}>Clear</Button>}
      </Box>

      {/* Table — scrollable */}
      <Box sx={{ flex: 1, overflow: 'auto' }}>
        {error && <Alert severity="error" sx={{ m: 2 }}>{error}</Alert>}
        {loading && <Box sx={{ display: 'flex', justifyContent: 'center', py: 6 }}><CircularProgress /></Box>}
        {!loading && !error && users.length === 0 && (
          <Typography color="text.secondary" textAlign="center" py={6}>No users found.</Typography>
        )}
        {!loading && users.length > 0 && (
          <TableContainer>
            <Table stickyHeader size="small">
              <TableHead>
                <TableRow>
                  {['User', 'Email', 'Roles', 'Email Status',
                    ...(perms.has('users.update') || perms.has('users.delete') || perms.has('roles.manage') ? ['Actions'] : [])
                  ].map(h => (
                    <TableCell key={h}>{h}</TableCell>
                  ))}
                </TableRow>
              </TableHead>
              <TableBody>
                {users.map(u => (
                  <TableRow key={u.id} hover>
                    <TableCell>
                      <Typography fontWeight={600} fontSize={14}>{u.name}</Typography>
                      <Typography variant="caption" color="text.secondary">ID #{u.id}</Typography>
                    </TableCell>
                    <TableCell sx={{ color: 'text.secondary', fontSize: 13 }}>{u.email}</TableCell>
                    <TableCell>
                      <Box sx={{ display: 'flex', gap: 0.5, flexWrap: 'wrap' }}>
                        {(u.roles || []).length === 0
                          ? <Typography variant="caption" color="text.secondary">—</Typography>
                          : (u.roles || []).map(r => <Chip key={r.id} label={r.name} size="small" />)
                        }
                      </Box>
                    </TableCell>
                    <TableCell>
                      <Chip
                        label={u.email_verified_at ? 'Verified' : 'Pending'}
                        color={u.email_verified_at ? 'success' : 'warning'}
                        size="small" variant="outlined"
                      />
                    </TableCell>
                    {(perms.has('users.update') || perms.has('users.delete') || perms.has('roles.manage')) && (
                      <TableCell>
                        <Box sx={{ display: 'flex', gap: 0.75 }}>
                          {perms.has('users.update')  && <Button size="small" variant="outlined" onClick={() => setModal({ type: 'edit',   data: u })}>Edit</Button>}
                          {perms.has('roles.manage')  && <Button size="small" variant="outlined" onClick={() => setModal({ type: 'roles',  data: u })}>Roles</Button>}
                          {perms.has('users.delete')  && <Button size="small" variant="outlined" color="error" onClick={() => setModal({ type: 'delete', data: u })}>Delete</Button>}
                        </Box>
                      </TableCell>
                    )}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
        )}
      </Box>

      {/* Pagination footer */}
      {meta && meta.last_page > 1 && (
        <Box sx={{ px: 3, py: 1.5, borderTop: '1px solid #e2e8f0', bgcolor: 'background.paper', display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexShrink: 0 }}>
          <Typography variant="caption" color="text.secondary">
            Page {meta.current_page} of {meta.last_page} — {meta.total} total
          </Typography>
          <Box sx={{ display: 'flex', gap: 1 }}>
            <Button size="small" variant="outlined" onClick={() => setPage(p => p - 1)} disabled={page <= 1}>← Prev</Button>
            <Button size="small" variant="outlined" onClick={() => setPage(p => p + 1)} disabled={page >= meta.last_page}>Next →</Button>
          </Box>
        </Box>
      )}

      {/* Modals */}
      {modal?.type === 'create' && perms.has('users.create')  && <CreateUserModal roles={roles} onClose={close} onDone={refresh} />}
      {modal?.type === 'edit'   && perms.has('users.update')  && <EditUserModal user={modal.data} onClose={close} onDone={refresh} />}
      {modal?.type === 'roles'  && perms.has('roles.manage')  && <AssignRolesModal user={modal.data} roles={roles} onClose={close} onDone={refresh} />}
      {modal?.type === 'delete' && perms.has('users.delete')  && (
        <ConfirmDialog
          title="Delete User"
          message={`Delete ${modal.data.name} (${modal.data.email})? This cannot be undone.`}
          confirmLabel="Delete" confirmColor="error"
          onClose={close}
          onConfirm={async () => {
            const r = await req('DELETE', `/users/${modal.data.id}`)
            if (r.ok && r.data.success) refresh()
          }}
        />
      )}
    </Box>
  )
}

// ── User modals ───────────────────────────────────────────────────────────────

function CreateUserModal({ roles, onClose, onDone }) {
  const [f, setF]        = useState({ name: '', email: '', password: '', password_confirmation: '' })
  const [roleIds, setRI] = useState([])
  const [errs, setErrs]  = useState({})
  const [alert, setA]    = useState('')
  const [loading, setL]  = useState(false)
  const set = k => e => setF(p => ({ ...p, [k]: e.target.value }))
  const toggle = id => setRI(p => p.includes(id) ? p.filter(x => x !== id) : [...p, id])

  const submit = async () => {
    setErrs({}); setA(''); setL(true)
    const body = { ...f }
    if (roleIds.length) body.role_ids = roleIds
    const r = await req('POST', '/users', body)
    setL(false)
    if (r.ok && r.data.success) onDone()
    else { setErrs(r.data.errors || {}); setA(r.data.message || 'Failed.') }
  }

  return (
    <Dialog open onClose={onClose} fullWidth maxWidth="sm">
      <DialogTitle fontWeight={700}>Add New User</DialogTitle>
      <DialogContent dividers>
        {alert && <Alert severity="error" sx={{ mb: 2 }}>{alert}</Alert>}
        <TextField fullWidth label="Full name"        value={f.name}  onChange={set('name')}  sx={{ mb: 2 }} autoFocus error={!!errs.name?.[0]}  helperText={errs.name?.[0]} />
        <TextField fullWidth label="Email address"    value={f.email} onChange={set('email')}  sx={{ mb: 2 }} type="email" error={!!errs.email?.[0]} helperText={errs.email?.[0]} />
        <TextField fullWidth label="Password"         value={f.password} onChange={set('password')} sx={{ mb: 2 }} type="password" error={!!errs.password?.[0]} helperText={errs.password?.[0] || 'Min 8 characters'} />
        <TextField fullWidth label="Confirm password" value={f.password_confirmation} onChange={set('password_confirmation')} sx={{ mb: 2 }} type="password" />
        {roles.length > 0 && (
          <>
            <Typography variant="body2" fontWeight={600} mb={1}>Assign Roles</Typography>
            <FormGroup row>
              {roles.map(r => (
                <FormControlLabel key={r.id} control={<Checkbox checked={roleIds.includes(r.id)} onChange={() => toggle(r.id)} size="small" />} label={r.name} />
              ))}
            </FormGroup>
          </>
        )}
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <Button variant="contained" onClick={submit} disabled={loading}>
          {loading ? <CircularProgress size={18} color="inherit" /> : 'Create User'}
        </Button>
      </DialogActions>
    </Dialog>
  )
}

function EditUserModal({ user, onClose, onDone }) {
  const [f, setF]       = useState({ name: user.name, email: user.email })
  const [errs, setErrs] = useState({})
  const [alert, setA]   = useState('')
  const [loading, setL] = useState(false)
  const set = k => e => setF(p => ({ ...p, [k]: e.target.value }))

  const submit = async () => {
    setErrs({}); setA(''); setL(true)
    const body = {}
    if (f.name !== user.name)   body.name  = f.name
    if (f.email !== user.email) body.email = f.email
    const r = await req('PUT', `/users/${user.id}`, body)
    setL(false)
    if (r.ok && r.data.success) onDone()
    else { setErrs(r.data.errors || {}); setA(r.data.message || 'Failed.') }
  }

  return (
    <Dialog open onClose={onClose} fullWidth maxWidth="sm">
      <DialogTitle fontWeight={700}>Edit — {user.name}</DialogTitle>
      <DialogContent dividers>
        {alert && <Alert severity="error" sx={{ mb: 2 }}>{alert}</Alert>}
        <TextField fullWidth label="Full name"     value={f.name}  onChange={set('name')}  sx={{ mb: 2 }} autoFocus error={!!errs.name?.[0]}  helperText={errs.name?.[0]} />
        <TextField fullWidth label="Email address" value={f.email} onChange={set('email')}  sx={{ mb: 1 }} type="email" error={!!errs.email?.[0]} helperText={errs.email?.[0]} />
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <Button variant="contained" onClick={submit} disabled={loading}>
          {loading ? <CircularProgress size={18} color="inherit" /> : 'Save Changes'}
        </Button>
      </DialogActions>
    </Dialog>
  )
}

function AssignRolesModal({ user, roles, onClose, onDone }) {
  const currentRoleId    = (user.roles || [])[0]?.id ?? ''
  const [selected, setS] = useState(String(currentRoleId))
  const [alert, setA]    = useState('')
  const [loading, setL]  = useState(false)

  const submit = async () => {
    setA(''); setL(true)
    const role_ids = selected ? [parseInt(selected)] : []
    const r = await req('POST', `/users/${user.id}/roles`, { role_ids })
    setL(false)
    if (r.ok && r.data.success) onDone()
    else setA(r.data.message || 'Failed.')
  }

  return (
    <Dialog open onClose={onClose} fullWidth maxWidth="xs">
      <DialogTitle fontWeight={700}>Roles — {user.name}</DialogTitle>
      <DialogContent dividers>
        {alert && <Alert severity="error" sx={{ mb: 2 }}>{alert}</Alert>}
        <Typography variant="body2" color="text.secondary" mb={2}>
          Select a role to assign. Saving replaces the existing assignment.
        </Typography>
        {roles.length === 0
          ? <Typography variant="body2" color="text.secondary">No roles available.</Typography>
          : <RadioGroup value={selected} onChange={e => setS(e.target.value)}>
              {roles.map(r => (
                <FormControlLabel key={r.id} value={String(r.id)}
                  control={<Radio size="small" />}
                  label={
                    <Box>
                      <Typography fontWeight={600} fontSize={14}>{r.name}</Typography>
                      {r.description && <Typography variant="caption" color="text.secondary">{r.description}</Typography>}
                    </Box>
                  }
                />
              ))}
            </RadioGroup>
        }
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <Button variant="contained" onClick={submit} disabled={loading}>
          {loading ? <CircularProgress size={18} color="inherit" /> : 'Save Role'}
        </Button>
      </DialogActions>
    </Dialog>
  )
}

function ConfirmDialog({ title, message, confirmLabel, confirmColor = 'error', onClose, onConfirm }) {
  const [loading, setL] = useState(false)
  const run = async () => { setL(true); await onConfirm(); setL(false) }
  return (
    <Dialog open onClose={onClose} fullWidth maxWidth="xs">
      <DialogTitle fontWeight={700}>{title}</DialogTitle>
      <DialogContent><Typography>{message}</Typography></DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose}>Cancel</Button>
        <Button variant="contained" color={confirmColor} onClick={run} disabled={loading}>
          {loading ? <CircularProgress size={18} color="inherit" /> : confirmLabel}
        </Button>
      </DialogActions>
    </Dialog>
  )
}

// ── Roles section ─────────────────────────────────────────────────────────────

function RolesSection({ perms }) {
  const [roles, setRoles]  = useState([])
  const [loading, setL]    = useState(true)
  const [error, setError]  = useState('')
  const [createOpen, setC] = useState(false)

  const load = async () => {
    setL(true); setError('')
    const r = await req('GET', '/roles')
    setL(false)
    if (r.ok && r.data.success) setRoles(r.data.data || [])
    else setError(r.data.message || 'Failed to load roles.')
  }

  useEffect(() => { load() }, [])

  return (
    <Box sx={{ display: 'flex', flexDirection: 'column', height: '100%', overflow: 'hidden' }}>

      {/* Header */}
      <Box sx={{ px: 3, py: 2, bgcolor: 'background.paper', borderBottom: '1px solid #e2e8f0', display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexShrink: 0 }}>
        <Box>
          <Typography variant="h6" fontWeight={700}>Roles & Permissions</Typography>
          <Typography variant="caption" color="text.secondary">Define access levels and capabilities for your users.</Typography>
        </Box>
        {perms.has('roles.manage') && <Button variant="contained" onClick={() => setC(true)}>+ Create Role</Button>}
      </Box>

      {/* Scrollable list */}
      <Box sx={{ flex: 1, overflow: 'auto', p: 3 }}>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        {loading && <Box sx={{ display: 'flex', justifyContent: 'center', py: 6 }}><CircularProgress /></Box>}
        {!loading && roles.length === 0 && !error && (
          <Typography color="text.secondary" textAlign="center" py={6}>No roles yet. Create your first role.</Typography>
        )}
        <Box sx={{ display: 'flex', flexDirection: 'column', gap: 1.5 }}>
          {roles.map(role => {
            const flatPerms = (v) => {
              if (!v) return []
              if (typeof v === 'string') return [v]
              if (Array.isArray(v)) return v.flatMap(flatPerms)
              if (typeof v === 'object') return Object.values(v).flatMap(flatPerms)
              return []
            }
            const flat = flatPerms(role.permissions)
            return (
              <Paper key={role.id} variant="outlined" sx={{ p: 2.5 }}>
                <Box sx={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 2 }}>
                  <Box sx={{ flex: 1 }}>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 0.5 }}>
                      <Typography fontWeight={700} fontSize={15}>{role.name}</Typography>
                      <Chip label={role.slug} size="small" variant="outlined" sx={{ fontSize: 11, height: 20 }} />
                    </Box>
                    {role.description && <Typography variant="body2" color="text.secondary" mb={1}>{role.description}</Typography>}
                    <Box sx={{ display: 'flex', gap: 0.5, flexWrap: 'wrap' }}>
                      {flat.length === 0
                        ? <Typography variant="caption" color="text.secondary">No permissions</Typography>
                        : flat.map((p, i) => <Chip key={i} label={p.name || p.slug || p} size="small" color="success" variant="outlined" sx={{ fontSize: 11 }} />)
                      }
                    </Box>
                  </Box>
                  <Typography variant="caption" color="text.secondary">#{role.id}</Typography>
                </Box>
              </Paper>
            )
          })}
        </Box>
      </Box>

      {createOpen && perms.has('roles.manage') && <CreateRoleModal onClose={() => setC(false)} onDone={() => { setC(false); load() }} />}
    </Box>
  )
}

function CreateRoleModal({ onClose, onDone }) {
  const [tab, setTab]             = useState(0)
  const [f, setF]                 = useState({ name: '', slug: '', description: '' })
  const [selected, setSelected]   = useState([])
  const [availPerms, setAvail]    = useState([])
  const [expanded, setExpanded]   = useState({})
  const [permsLoading, setPL]     = useState(true)
  const [errs, setErrs]           = useState({})
  const [alert, setA]             = useState('')
  const [loading, setL]           = useState(false)

  useEffect(() => {
    req('GET', '/permissions').then(r => {
      setPL(false)
      if (r.ok && r.data.success) {
        const data = r.data.data || []
        setAvail(data)
        const exp = {}
        data.forEach(p => { exp[p.permission] = true })
        setExpanded(exp)
      }
    })
  }, [])

  const slugify = s => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
  const onName  = e => {
    const name = e.target.value
    setF(p => ({ ...p, name, slug: p.slug === slugify(p.name) ? slugify(name) : p.slug }))
  }

  const label = s => s.split(/[._]/).map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ')

  const allSlugs      = () => availPerms.flatMap(p => Object.values(p.module).flat())
  const categorySlugs = p  => Object.values(p.module).flat()

  const toggleSlugs = (slugs) => {
    const all = slugs.every(s => selected.includes(s))
    setSelected(prev => all ? prev.filter(x => !slugs.includes(x)) : [...new Set([...prev, ...slugs])])
  }

  const submit = async () => {
    setErrs({}); setA(''); setL(true)
    const body = { name: f.name, slug: f.slug }
    if (f.description)  body.description    = f.description
    if (selected.length) body.permission_ids = selected
    const r = await req('POST', '/roles', body)
    setL(false)
    if (r.ok && r.data.success) onDone()
    else { setErrs(r.data.errors || {}); setA(r.data.message || 'Failed.') }
  }

  const TAB_SX = active => ({
    px: 3, py: 1.5, cursor: 'pointer', fontSize: 14, userSelect: 'none',
    fontWeight: active ? 700 : 400,
    borderBottom: active ? '2px solid #2563eb' : '2px solid transparent',
    color: active ? '#2563eb' : '#64748b',
  })

  return (
    <Dialog open onClose={onClose} fullWidth maxWidth="md">
      <DialogTitle sx={{ pb: 0 }}>
        <Typography fontWeight={700} fontSize={18}>Create New Role</Typography>
        <Typography variant="body2" color="text.secondary">Create a new role and assign permissions to it</Typography>
      </DialogTitle>

      {/* Tab bar */}
      <Box sx={{ display: 'flex', borderBottom: '1px solid #e2e8f0', mt: 1 }}>
        <Box onClick={() => setTab(0)} sx={TAB_SX(tab === 0)}>Basic Information</Box>
        <Box onClick={() => setTab(1)} sx={TAB_SX(tab === 1)}>Permissions</Box>
      </Box>

      <DialogContent sx={{ p: 3, minHeight: 380 }}>
        {alert && <Alert severity="error" sx={{ mb: 2 }}>{alert}</Alert>}

        {/* ── Tab 0: Basic Info ── */}
        {tab === 0 && (
          <Box>
            <Typography variant="body2" fontWeight={600} mb={0.5}>Role Name *</Typography>
            <TextField fullWidth placeholder="e.g., HR Manager" value={f.name} onChange={onName}
              sx={{ mb: 2 }} autoFocus error={!!errs.name?.[0]} helperText={errs.name?.[0]} />
            <Typography variant="body2" fontWeight={600} mb={0.5}>Slug *</Typography>
            <TextField fullWidth placeholder="e.g., hr-manager" value={f.slug}
              onChange={e => setF(p => ({ ...p, slug: e.target.value }))}
              sx={{ mb: 0.5 }} error={!!errs.slug?.[0]} helperText={errs.slug?.[0]} />
            <Typography variant="caption" color="text.secondary" display="block" mb={2}>
              Lowercase alphanumeric with hyphens only
            </Typography>
            <Typography variant="body2" fontWeight={600} mb={0.5}>Description</Typography>
            <TextField fullWidth multiline rows={3} placeholder="Role description…"
              value={f.description} onChange={e => setF(p => ({ ...p, description: e.target.value }))} />
          </Box>
        )}

        {/* ── Tab 1: Permissions ── */}
        {tab === 1 && (
          <Box>
            {permsLoading && <Box sx={{ display: 'flex', justifyContent: 'center', py: 4 }}><CircularProgress /></Box>}
            {!permsLoading && availPerms.length === 0 && (
              <Typography color="text.secondary" py={4} textAlign="center">No permissions defined.</Typography>
            )}
            {!permsLoading && availPerms.length > 0 && (() => {
              const all = allSlugs()
              const allChecked = all.length > 0 && all.every(s => selected.includes(s))
              const allIndet   = !allChecked && all.some(s => selected.includes(s))
              return (
                <>
                  {/* Global select-all row */}
                  <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', pb: 1.5, mb: 1.5, borderBottom: '1px solid #e2e8f0' }}>
                    <Typography fontWeight={700} fontSize={14}>Permissions</Typography>
                    <FormControlLabel labelPlacement="start" label="Select all" sx={{ m: 0, gap: 0.5 }}
                      control={<Checkbox checked={allChecked} indeterminate={allIndet} size="small" onChange={() => toggleSlugs(all)} />} />
                  </Box>

                  {availPerms.map(permRec => {
                    const catSlugs    = categorySlugs(permRec)
                    const catChecked  = catSlugs.every(s => selected.includes(s))
                    const catIndet    = !catChecked && catSlugs.some(s => selected.includes(s))
                    const isExpanded  = expanded[permRec.permission] !== false
                    const catLabel    = label(permRec.permission)
                    return (
                      <Paper key={permRec.id} variant="outlined" sx={{ mb: 1.5 }}>
                        {/* Category header */}
                        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', px: 2, py: 1.25, bgcolor: '#f8fafc', cursor: 'pointer' }}
                          onClick={() => setExpanded(p => ({ ...p, [permRec.permission]: !isExpanded }))}>
                          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                            <Typography fontSize={13} color="text.secondary">{isExpanded ? '▲' : '▼'}</Typography>
                            <Typography fontWeight={700} fontSize={14}>{catLabel}</Typography>
                          </Box>
                          <FormControlLabel labelPlacement="start" label={`Select all ${catLabel}`} sx={{ m: 0, gap: 0.5 }}
                            onClick={e => e.stopPropagation()}
                            control={<Checkbox checked={catChecked} indeterminate={catIndet} size="small"
                              onChange={() => toggleSlugs(catSlugs)} onClick={e => e.stopPropagation()} />} />
                        </Box>

                        {isExpanded && (
                          <Box sx={{ px: 2, pb: 2 }}>
                            {Object.entries(permRec.module).map(([modName, slugs]) => {
                              const modChecked = slugs.every(s => selected.includes(s))
                              const modIndet   = !modChecked && slugs.some(s => selected.includes(s))
                              return (
                                <Box key={modName} sx={{ mt: 1.5 }}>
                                  <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 0.75 }}>
                                    <Typography fontSize={12} fontWeight={600} color="text.secondary" textTransform="uppercase" letterSpacing={0.5}>
                                      {label(modName)}
                                    </Typography>
                                    <FormControlLabel labelPlacement="start" label="Select all" sx={{ m: 0, gap: 0.5 }}
                                      control={<Checkbox checked={modChecked} indeterminate={modIndet} size="small" onChange={() => toggleSlugs(slugs)} />} />
                                  </Box>
                                  <FormGroup>
                                    {slugs.map(slug => (
                                      <FormControlLabel key={slug} sx={{ ml: 0 }}
                                        control={<Checkbox checked={selected.includes(slug)} size="small" onChange={() => toggleSlugs([slug])} />}
                                        label={<Typography fontSize={13}>{label(slug)}</Typography>} />
                                    ))}
                                  </FormGroup>
                                </Box>
                              )
                            })}
                          </Box>
                        )}
                      </Paper>
                    )
                  })}
                </>
              )
            })()}
          </Box>
        )}
      </DialogContent>

      <DialogActions sx={{ px: 3, py: 2, borderTop: '1px solid #e2e8f0' }}>
        <Button onClick={onClose}>Cancel</Button>
        <Button variant="contained" onClick={submit} disabled={loading}>
          {loading ? <CircularProgress size={18} color="inherit" /> : 'Create Role'}
        </Button>
      </DialogActions>
    </Dialog>
  )
}

// ── Profile section ───────────────────────────────────────────────────────────

function ProfileSection({ user }) {
  const [pw, setPw]       = useState({ current_password: '', password: '', password_confirmation: '' })
  const [pwAlert, setPwA] = useState(null)
  const [pwLoading, setPwL] = useState(false)
  const setP = k => e => setPw(p => ({ ...p, [k]: e.target.value }))

  const [resendAlert, setRA]   = useState(null)
  const [resendLoading, setRL] = useState(false)
  const [verifyUrl, setVU]     = useState('')
  const [verifyAlert, setVA]   = useState(null)
  const [verifyLoading, setVL] = useState(false)

  const [resetAlert, setResetA]   = useState(null)
  const [resetLoading, setResetL] = useState(false)
  const [resetF, setResetF]       = useState({ email: '', token: '', password: '', password_confirmation: '' })
  const setR = k => e => setResetF(p => ({ ...p, [k]: e.target.value }))

  const changePass = async () => {
    setPwA(null); setPwL(true)
    const r = await req('POST', '/change-password', pw)
    setPwL(false)
    setPwA({ severity: r.data.success ? 'success' : 'error', msg: r.data.message || 'Done.' })
    if (r.data.success) setPw({ current_password: '', password: '', password_confirmation: '' })
  }

  const resend = async () => {
    setRA(null); setRL(true)
    const r = await req('POST', '/resend-verification-email')
    setRL(false)
    setRA({ severity: r.data.success ? 'success' : 'error', msg: r.data.message || 'Done.' })
  }

  const verifyWithUrl = async () => {
    if (!verifyUrl) return
    setVA(null); setVL(true)
    try {
      const res = await fetch(verifyUrl, { credentials: 'include', headers: { Accept: 'application/json' } })
      const data = await res.json()
      setVA({ severity: data.success ? 'success' : 'error', msg: data.message || 'Done.' })
    } catch { setVA({ severity: 'error', msg: 'Request failed.' }) }
    setVL(false)
  }

  const resetPass = async () => {
    setResetA(null); setResetL(true)
    const r = await req('POST', '/auth/reset-password', resetF)
    setResetL(false)
    setResetA({ severity: r.data.success ? 'success' : 'error', msg: r.data.message || 'Done.' })
  }

  const SectionCard = ({ title, children }) => (
    <Paper variant="outlined" sx={{ p: 3, mb: 2 }}>
      <Typography fontWeight={700} fontSize={15} mb={2}>{title}</Typography>
      {children}
    </Paper>
  )

  return (
    <Box sx={{ display: 'flex', flexDirection: 'column', height: '100%', overflow: 'hidden' }}>
      {/* Header */}
      <Box sx={{ px: 3, py: 2, bgcolor: 'background.paper', borderBottom: '1px solid #e2e8f0', flexShrink: 0 }}>
        <Typography variant="h6" fontWeight={700}>My Profile</Typography>
        <Typography variant="caption" color="text.secondary">Account settings and security options.</Typography>
      </Box>

      {/* Scrollable content */}
      <Box sx={{ flex: 1, overflow: 'auto', p: 3 }}>

        <SectionCard title="Account">
          <Box sx={{ display: 'grid', gridTemplateColumns: '130px 1fr', rowGap: 1.25, fontSize: 14 }}>
            {[['Name', user?.name], ['Email', user?.email]].map(([k, v]) => (
              <>
                <Typography key={k + 'k'} fontSize={13} color="text.secondary" fontWeight={500}>{k}</Typography>
                <Typography key={k + 'v'} fontSize={13} fontWeight={600}>{v}</Typography>
              </>
            ))}
            <Typography fontSize={13} color="text.secondary" fontWeight={500}>Email status</Typography>
            <Box>
              <Chip label={user?.email_verified_at ? 'Verified' : 'Not verified'}
                color={user?.email_verified_at ? 'success' : 'warning'} size="small" variant="outlined" />
            </Box>
            {(user?.roles || []).length > 0 && (
              <>
                <Typography fontSize={13} color="text.secondary" fontWeight={500}>Roles</Typography>
                <Box sx={{ display: 'flex', gap: 0.5, flexWrap: 'wrap' }}>
                  {user.roles.map(r => <Chip key={r.id} label={r.name} size="small" />)}
                </Box>
              </>
            )}
          </Box>
        </SectionCard>

        <SectionCard title="Email Verification">
          {resendAlert && <Alert severity={resendAlert.severity} sx={{ mb: 2 }}>{resendAlert.msg}</Alert>}
          <Button variant="outlined" size="small" onClick={resend} disabled={resendLoading} sx={{ mb: 2 }}>
            {resendLoading ? <CircularProgress size={16} /> : 'Resend Verification Email'}
          </Button>
          <Divider sx={{ my: 2 }} />
          <Typography variant="body2" color="text.secondary" mb={1}>Or paste the verification link from your email:</Typography>
          {verifyAlert && <Alert severity={verifyAlert.severity} sx={{ mb: 1 }}>{verifyAlert.msg}</Alert>}
          <Box sx={{ display: 'flex', gap: 1 }}>
            <TextField fullWidth value={verifyUrl} onChange={e => setVU(e.target.value)}
              placeholder="http://localhost:8000/api/auth/verify-email/…" />
            <Button variant="contained" onClick={verifyWithUrl} disabled={verifyLoading}>
              {verifyLoading ? <CircularProgress size={18} color="inherit" /> : 'Verify'}
            </Button>
          </Box>
        </SectionCard>

        <SectionCard title="Change Password">
          {pwAlert && <Alert severity={pwAlert.severity} sx={{ mb: 2 }}>{pwAlert.msg}</Alert>}
          <TextField fullWidth label="Current password"  type="password" value={pw.current_password}        onChange={setP('current_password')} sx={{ mb: 2 }} />
          <TextField fullWidth label="New password"      type="password" value={pw.password}                onChange={setP('password')}         sx={{ mb: 2 }} helperText="Min 8 characters" />
          <TextField fullWidth label="Confirm password"  type="password" value={pw.password_confirmation}   onChange={setP('password_confirmation')} sx={{ mb: 2 }} />
          <Button variant="contained" onClick={changePass} disabled={pwLoading}>
            {pwLoading ? <CircularProgress size={18} color="inherit" /> : 'Update Password'}
          </Button>
        </SectionCard>

        <SectionCard title="Reset Password (via Token)">
          <Typography variant="body2" color="text.secondary" mb={2}>Use a token from a reset email to set a new password.</Typography>
          {resetAlert && <Alert severity={resetAlert.severity} sx={{ mb: 2 }}>{resetAlert.msg}</Alert>}
          <TextField fullWidth label="Email"            type="email"    value={resetF.email}    onChange={setR('email')}    sx={{ mb: 2 }} />
          <TextField fullWidth label="Token"                            value={resetF.token}    onChange={setR('token')}    sx={{ mb: 2 }} />
          <TextField fullWidth label="New password"     type="password" value={resetF.password} onChange={setR('password')} sx={{ mb: 2 }} />
          <TextField fullWidth label="Confirm password" type="password" value={resetF.password_confirmation} onChange={setR('password_confirmation')} sx={{ mb: 2 }} />
          <Button variant="contained" onClick={resetPass} disabled={resetLoading}>
            {resetLoading ? <CircularProgress size={18} color="inherit" /> : 'Reset Password'}
          </Button>
        </SectionCard>

      </Box>
    </Box>
  )
}

// ── Root App ──────────────────────────────────────────────────────────────────

function AppInner() {
  const isResetPath   = window.location.pathname === '/reset-password'
  const urlParams     = new URLSearchParams(window.location.search)
  const socialError   = urlParams.get('social_error')

  // Clean up social callback params from the URL immediately
  if (urlParams.has('social_login') || urlParams.has('social_error')) {
    window.history.replaceState(null, '', window.location.pathname)
  }

  const [page, setPage]         = useState(isResetPath ? 'reset-password' : 'login')
  const [currentUser, setUser]  = useState(null)
  const [checking, setChecking] = useState(!isResetPath)

  useEffect(() => {
    if (isResetPath) return
    // social_login=1 means the OAuth callback already created a session — just fetch the user
    req('GET', '/user').then(r => {
      if (r.ok && r.data.success) { setUser(r.data.data); setPage('dashboard') }
      setChecking(false)
    })
  }, [])

  const handleLogin  = user => { setUser(user); setPage('dashboard') }
  const handleLogout = async () => { await req('POST', '/auth/logout'); setUser(null); setPage('login') }

  const socialErrorMsg = {
    auth_failed:    'Social login failed. Please try again.',
    account_locked: 'Your account is locked. Contact an administrator.',
    unsupported:    'That login provider is not supported.',
  }[socialError] ?? (socialError ? 'Social login failed.' : '')

  if (checking) return (
    <Box sx={{ height: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <CircularProgress />
    </Box>
  )

  if (page === 'dashboard')      return <Dashboard user={currentUser} onLogout={handleLogout} />
  if (page === 'register')       return <RegisterPage goLogin={() => setPage('login')} />
  if (page === 'forgot')         return <ForgotPage   goLogin={() => setPage('login')} />
  if (page === 'reset-password') return <ResetPasswordPage goLogin={() => { window.history.replaceState(null, '', '/'); setPage('login') }} />
  return <LoginPage onLogin={handleLogin} goRegister={() => setPage('register')} goForgot={() => setPage('forgot')} initialError={socialErrorMsg} />
}

export default function App() {
  return (
    <ThemeProvider theme={theme}>
      <CssBaseline />
      <AppInner />
    </ThemeProvider>
  )
}
