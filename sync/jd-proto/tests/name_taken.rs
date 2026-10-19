//! The data of a `name_taken` refusal, as the platform sends it: the example
//! in `fixtures/name_taken_refusal.json`, which the platform's own test
//! (`drive_sync_contract`) and jd-sim's server are held to as well. The three
//! drifted once: the simulator named the holder and the platform never did,
//! so the client's adoption of its own upload ran only in the simulator.

use jd_proto::ProtoError;
use serde_json::Value;

fn refusal(kind: &str) -> ProtoError {
    let example: Value =
        serde_json::from_str(include_str!("fixtures/name_taken_refusal.json")).expect("the example parses");
    ProtoError::Api {
        status: 422,
        errortype: "ActionError".into(),
        message: "A file with that name already exists here.".into(),
        data: example[kind].clone(),
    }
}

#[test]
fn a_file_holder_is_read_from_the_refusal_the_platform_sends() {
    let e = refusal("file");
    assert!(e.name_taken());
    let holder = e.name_holder().expect("the holder is named");
    assert_eq!(holder.entity_type, "file");
    assert_eq!(holder.id, 1963621);
    assert_eq!(holder.name, "notes.txt~");
    assert_eq!(
        holder.sha256.as_deref(),
        Some("833be3070c2f9a6a1f0de7c3d2b1a4e5f60718293a4b5c6d7e8f9a0b1c2d3e4f")
    );
}

#[test]
fn a_folder_holder_is_read_without_a_hash() {
    let holder = refusal("folder").name_holder().expect("the holder is named");
    assert_eq!(holder.entity_type, "folder");
    assert_eq!(holder.id, 455809);
    assert_eq!(holder.sha256, None);
}

#[test]
fn a_refusal_whose_holder_is_gone_is_still_a_taken_name() {
    // The insert race's loser asks for the holder again, and it can have gone.
    let e = ProtoError::Api {
        status: 422,
        errortype: "ActionError".into(),
        message: "A file with that name already exists here.".into(),
        data: serde_json::json!({ "reason": "name_taken" }),
    };
    assert!(e.name_taken());
    assert!(e.name_holder().is_none());
}
