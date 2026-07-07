import SwiftUI
import JoineryKit

/// The Move/Labels control on an open thread — the web reader's
/// `buildFolderControl()` (plugins/mailbox/assets/mailbox_reader.js) as a
/// sheet, matching the Android picker's behavior: exclusive feeds get a
/// single-pick "Move to" (radio rows — choosing a folder relocates the
/// thread); non-exclusive feeds (Gmail-style) get "Labels" with a checkbox
/// per folder. Both end with a create row; the sync push creates the folder
/// on the source and files the thread into it.
struct FolderPickerSheet: View {
    let mailbox: Mailbox
    let currentIDs: Set<Int>
    let onMove: (MailFolder) -> Void
    let onToggle: (MailFolder, Bool) -> Void
    let onCreate: (String) -> Void

    @Environment(\.dismiss) private var dismiss
    @State private var newName = ""

    var body: some View {
        NavigationStack {
            List {
                Section {
                    ForEach(mailbox.folders) { folder in
                        let isMember = currentIDs.contains(folder.id)
                        if mailbox.foldersExclusive {
                            Button {
                                if !isMember { onMove(folder) }
                            } label: {
                                HStack {
                                    Text(folder.name).foregroundStyle(.primary)
                                    Spacer()
                                    if isMember {
                                        Image(systemName: "checkmark").foregroundStyle(Color.accentColor)
                                    }
                                }
                            }
                            .accessibilityIdentifier("mail_folder_option_\(folder.id)")
                        } else {
                            Toggle(folder.name, isOn: Binding(
                                get: { isMember },
                                set: { onToggle(folder, $0) }
                            ))
                            .accessibilityIdentifier("mail_folder_option_\(folder.id)")
                        }
                    }
                }
                Section {
                    HStack {
                        TextField(mailbox.foldersExclusive ? "New folder…" : "New label…", text: $newName)
                            .accessibilityIdentifier("mail_folder_new")
                        Button("Add") {
                            let name = newName.trimmingCharacters(in: .whitespaces)
                            guard !name.isEmpty else { return }
                            newName = ""
                            onCreate(name)
                        }
                        .disabled(newName.trimmingCharacters(in: .whitespaces).isEmpty)
                        .accessibilityIdentifier("mail_folder_create")
                    }
                }
            }
            .accessibilityIdentifier("mail_folder_sheet")
            .navigationTitle(mailbox.foldersExclusive ? "Move to" : "Labels")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }
                }
            }
        }
        .presentationDetents([.medium, .large])
    }
}
