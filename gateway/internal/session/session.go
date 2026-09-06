// Package session tracks pending and live terminal sessions.
//
// The gateway is the authority on what is actually running; LibreNMS holds a
// reconciled view. That split is deliberate: the plugin must never be able to
// believe a session is alive when the process holding its socket has gone.
package session

import (
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"sync"
	"time"

	"github.com/adn/librenms-webterm/gateway/internal/proto"
)

var (
	ErrNotFound      = errors.New("session: no such pending session")
	ErrTicketInvalid = errors.New("session: ticket invalid or already used")
	ErrExpired       = errors.New("session: pending session expired")
	ErrAtCapacity    = errors.New("session: gateway at capacity")
	ErrNoCredential  = errors.New("session: credential not supplied")
)

// Target is where the SSH connection goes. The gateway never resolves DNS, so
// IP is always a literal chosen by the plugin.
type Target struct {
	IP               string   `json:"ip"`
	Port             int      `json:"port"`
	HostnameLabel    string   `json:"hostname_label"`
	HostKeyPolicy    string   `json:"host_key_policy"`
	KnownHosts       []string `json:"known_hosts"`
	AlgorithmProfile string   `json:"algorithm_profile"`
}

type Auth struct {
	Method       string `json:"method"`
	Username     string `json:"username"`
	KeyAlgorithm string `json:"key_algorithm,omitempty"`

	// Supplied in phase 3 only, over loopback.
	Certificate string `json:"certificate,omitempty"`
	Password    string `json:"password,omitempty"`
	PrivateKey  string `json:"private_key,omitempty"`
	Passphrase  string `json:"passphrase,omitempty"`
}

type Limits struct {
	IdleTimeout int   `json:"idle_timeout"`
	MaxDuration int   `json:"max_duration"`
	WarnAt      []int `json:"warn_at"`
}

// Pending is a session that has been authorised but not yet connected.
type Pending struct {
	ID         string
	TicketHash []byte
	Target     Target
	Auth       Auth
	Limits     Limits
	ExpiresAt  time.Time

	// Ephemeral keypair for the signed-certificate flow. The private half never
	// leaves this process; the plugin only ever sees PublicKey.
	PublicKey  string
	privateKey []byte

	credentialed bool
	redeemed     bool
}

// Live is a connected session.
type Live struct {
	ID        string
	User      string
	Target    string
	StartedAt time.Time
	LastData  time.Time
	Close     func(code int, reason string)
	Notice    func(level, message string)
}

// Registry holds pending and live sessions. Safe for concurrent use.
type Registry struct {
	mu      sync.Mutex
	pending map[string]*Pending
	live    map[string]*Live
	max     int
	now     func() time.Time
}

func NewRegistry(max int) *Registry {
	return &Registry{
		pending: make(map[string]*Pending),
		live:    make(map[string]*Live),
		max:     max,
		now:     time.Now,
	}
}

// Create registers a pending session and returns the ticket the browser must
// present. The ticket is returned once and never stored: only its hash is kept,
// so a dump of gateway memory or of the plugin's database does not yield a
// usable ticket.
func (r *Registry) Create(p *Pending, ttl time.Duration) (string, error) {
	r.mu.Lock()
	defer r.mu.Unlock()

	r.reapLocked()

	if len(r.live)+len(r.pending) >= r.max {
		return "", ErrAtCapacity
	}

	raw := make([]byte, proto.TicketBytes)
	if _, err := rand.Read(raw); err != nil {
		return "", err
	}
	ticket := base64.RawURLEncoding.EncodeToString(raw)

	sum := sha256.Sum256([]byte(ticket))
	p.TicketHash = sum[:]
	p.ExpiresAt = r.now().Add(ttl)

	r.pending[p.ID] = p

	return ticket, nil
}

// SupplyCredential attaches the resolved secret to a pending session.
func (r *Registry) SupplyCredential(id string, auth Auth) error {
	r.mu.Lock()
	defer r.mu.Unlock()

	p, ok := r.pending[id]
	if !ok {
		return ErrNotFound
	}
	if r.now().After(p.ExpiresAt) {
		delete(r.pending, id)
		return ErrExpired
	}

	// The plugin pins the method; the gateway must not accept a different one.
	// Without this a compromised or buggy caller could downgrade a
	// certificate-only deployment to a long-lived password.
	if auth.Method != p.Auth.Method {
		return errors.New("session: credential method does not match the pinned method")
	}

	auth.Username = p.Auth.Username // server-derived; never taken from elsewhere
	p.Auth = auth
	p.credentialed = true

	return nil
}

// Redeem consumes a ticket exactly once.
//
// The compare-and-swap is the single-use guarantee: two browsers presenting the
// same ticket concurrently must not both get a shell. Comparison is constant
// time, and the entry is deleted before returning so no second caller can see
// it at all.
func (r *Registry) Redeem(ticket string) (*Pending, error) {
	sum := sha256.Sum256([]byte(ticket))

	r.mu.Lock()
	defer r.mu.Unlock()

	r.reapLocked()

	for id, p := range r.pending {
		if subtle.ConstantTimeCompare(p.TicketHash, sum[:]) != 1 {
			continue
		}

		if p.redeemed {
			return nil, ErrTicketInvalid
		}
		if r.now().After(p.ExpiresAt) {
			delete(r.pending, id)
			return nil, ErrExpired
		}
		if !p.credentialed {
			return nil, ErrNoCredential
		}

		p.redeemed = true
		delete(r.pending, id)

		return p, nil
	}

	return nil, ErrTicketInvalid
}

func (r *Registry) Activate(l *Live) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.live[l.ID] = l
}

func (r *Registry) Deactivate(id string) {
	r.mu.Lock()
	defer r.mu.Unlock()
	delete(r.live, id)
}

func (r *Registry) Get(id string) (*Live, bool) {
	r.mu.Lock()
	defer r.mu.Unlock()
	l, ok := r.live[id]

	return l, ok
}

// List returns a snapshot of live sessions for the plugin's reconciler.
func (r *Registry) List() []Live {
	r.mu.Lock()
	defer r.mu.Unlock()

	out := make([]Live, 0, len(r.live))
	for _, l := range r.live {
		out = append(out, *l)
	}

	return out
}

// PendingPrivateKey returns the ephemeral private key held for a pending
// session. Deliberately narrow: nothing else has any business reading it, and
// it never appears in List() or any JSON response.
func (r *Registry) PendingPrivateKey(id string) (string, bool) {
	r.mu.Lock()
	defer r.mu.Unlock()

	p, ok := r.pending[id]
	if !ok || len(p.privateKey) == 0 {
		return "", false
	}

	return string(p.privateKey), true
}

// Notice returns the in-band notification function for a live session.
func (r *Registry) Notice(id string) (func(level, message string), bool) {
	r.mu.Lock()
	defer r.mu.Unlock()

	l, ok := r.live[id]
	if !ok || l.Notice == nil {
		return nil, false
	}

	return l.Notice, true
}

func (r *Registry) Counts() (pending, live int) {
	r.mu.Lock()
	defer r.mu.Unlock()

	return len(r.pending), len(r.live)
}

// reapLocked drops expired pending sessions. Called on every mutation rather
// than from a timer: pending sessions live for 30 seconds, so there is never
// enough of a backlog to need a sweeper goroutine.
func (r *Registry) reapLocked() {
	now := r.now()
	for id, p := range r.pending {
		if now.After(p.ExpiresAt) {
			delete(r.pending, id)
		}
	}
}

// SetPrivateKey stores the ephemeral private key for a pending session.
func (p *Pending) SetPrivateKey(key []byte) {
	p.privateKey = key
}

func (p *Pending) PrivateKey() []byte {
	return p.privateKey
}

// TicketHashHex is exposed for the plugin's audit record, which stores the hash
// so that a database read cannot yield a usable ticket.
func (p *Pending) TicketHashHex() string {
	return hex.EncodeToString(p.TicketHash)
}
