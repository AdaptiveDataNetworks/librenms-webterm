package session

import (
	"sync"
	"testing"
	"time"
)

func pending(id string) *Pending {
	return &Pending{
		ID:     id,
		Target: Target{IP: "10.0.0.1", Port: 22},
		Auth:   Auth{Method: "password", Username: "netops"},
	}
}

func TestRedeemRequiresACredential(t *testing.T) {
	// A ticket that could be redeemed before the credential arrived would let a
	// fast browser open a session the plugin had not finished authorising.
	r := NewRegistry(10)
	ticket, err := r.Create(pending("s1"), time.Minute)
	if err != nil {
		t.Fatal(err)
	}

	if _, err := r.Redeem(ticket); err != ErrNoCredential {
		t.Fatalf("expected ErrNoCredential, got %v", err)
	}
}

func TestRedeemIsSingleUse(t *testing.T) {
	r := NewRegistry(10)
	ticket, _ := r.Create(pending("s1"), time.Minute)
	if err := r.SupplyCredential("s1", Auth{Method: "password", Password: "x"}); err != nil {
		t.Fatal(err)
	}

	if _, err := r.Redeem(ticket); err != nil {
		t.Fatalf("first redemption should succeed: %v", err)
	}
	if _, err := r.Redeem(ticket); err != ErrTicketInvalid {
		t.Fatalf("second redemption must fail, got %v", err)
	}
}

func TestConcurrentRedemptionYieldsExactlyOneWinner(t *testing.T) {
	// The property the whole handoff rests on: two browsers presenting the same
	// ticket must not both get a shell.
	r := NewRegistry(10)
	ticket, _ := r.Create(pending("s1"), time.Minute)
	_ = r.SupplyCredential("s1", Auth{Method: "password", Password: "x"})

	const n = 64
	var wg sync.WaitGroup
	results := make([]error, n)

	for i := 0; i < n; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			_, results[i] = r.Redeem(ticket)
		}(i)
	}
	wg.Wait()

	won := 0
	for _, err := range results {
		if err == nil {
			won++
		}
	}
	if won != 1 {
		t.Fatalf("expected exactly one successful redemption, got %d", won)
	}
}

func TestExpiredTicketIsRefused(t *testing.T) {
	r := NewRegistry(10)
	now := time.Now()
	r.now = func() time.Time { return now }

	ticket, _ := r.Create(pending("s1"), 30*time.Second)
	_ = r.SupplyCredential("s1", Auth{Method: "password", Password: "x"})

	r.now = func() time.Time { return now.Add(31 * time.Second) }

	if _, err := r.Redeem(ticket); err != ErrExpired && err != ErrTicketInvalid {
		t.Fatalf("expected expiry, got %v", err)
	}
}

func TestUnknownTicketIsRefused(t *testing.T) {
	r := NewRegistry(10)
	if _, err := r.Redeem("not-a-real-ticket"); err != ErrTicketInvalid {
		t.Fatalf("expected ErrTicketInvalid, got %v", err)
	}
}

func TestCredentialMethodCannotBeDowngraded(t *testing.T) {
	// The plugin pins the method. A gateway that accepted a different one would
	// let a certificate-only deployment be handed a long-lived password.
	r := NewRegistry(10)
	p := pending("s1")
	p.Auth = Auth{Method: "signed_certificate", Username: "netops"}
	_, _ = r.Create(p, time.Minute)

	err := r.SupplyCredential("s1", Auth{Method: "password", Password: "hunter2"})
	if err == nil {
		t.Fatal("expected a downgrade to be refused")
	}
}

func TestUsernameIsServerDerived(t *testing.T) {
	// Whatever the credential payload claims, the username stays the one the
	// plugin pinned.
	r := NewRegistry(10)
	_, _ = r.Create(pending("s1"), time.Minute)
	_ = r.SupplyCredential("s1", Auth{Method: "password", Username: "root", Password: "x"})

	ticket, _ := r.Create(pending("s2"), time.Minute)
	_ = r.SupplyCredential("s2", Auth{Method: "password", Username: "root", Password: "x"})

	p, err := r.Redeem(ticket)
	if err != nil {
		t.Fatal(err)
	}
	if p.Auth.Username != "netops" {
		t.Fatalf("username should be server-derived, got %q", p.Auth.Username)
	}
}

func TestCapacityIsEnforced(t *testing.T) {
	r := NewRegistry(2)
	if _, err := r.Create(pending("s1"), time.Minute); err != nil {
		t.Fatal(err)
	}
	if _, err := r.Create(pending("s2"), time.Minute); err != nil {
		t.Fatal(err)
	}
	if _, err := r.Create(pending("s3"), time.Minute); err != ErrAtCapacity {
		t.Fatalf("expected ErrAtCapacity, got %v", err)
	}
}

func TestExpiredPendingSessionsAreReaped(t *testing.T) {
	r := NewRegistry(10)
	now := time.Now()
	r.now = func() time.Time { return now }

	_, _ = r.Create(pending("s1"), 30*time.Second)
	r.now = func() time.Time { return now.Add(time.Minute) }

	// Any mutation reaps; capacity should be free again.
	if _, err := r.Create(pending("s2"), 30*time.Second); err != nil {
		t.Fatalf("expired pending session was not reaped: %v", err)
	}

	p, l := r.Counts()
	if p != 1 || l != 0 {
		t.Fatalf("expected 1 pending and 0 live, got %d/%d", p, l)
	}
}

func TestTicketsAreUnique(t *testing.T) {
	r := NewRegistry(1000)
	seen := make(map[string]bool)

	for i := 0; i < 500; i++ {
		p := pending(string(rune('a'+i%26)) + time.Now().Format("150405.000000000"))
		p.ID = p.ID + string(rune(i))
		ticket, err := r.Create(p, time.Minute)
		if err != nil {
			t.Fatal(err)
		}
		if seen[ticket] {
			t.Fatal("duplicate ticket generated")
		}
		seen[ticket] = true
	}
}
