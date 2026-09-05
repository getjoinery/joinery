package main

import "crypto/rand"

// readRandom fills b from the system CSPRNG.
func readRandom(b []byte) (int, error) {
	return rand.Read(b)
}
